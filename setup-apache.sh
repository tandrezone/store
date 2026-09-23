#!/usr/bin/env bash
#
# setup-apache.sh — one-time Apache VirtualHost bootstrap for chemheaven.cc
#
# Run from a fresh `git clone` of the store project on a Debian/Ubuntu host:
#
#     sudo ./setup-apache.sh
#
# What it does:
#   1. Exits cleanly if /etc/apache2/sites-available/chemheaven.cc.conf exists.
#   2. Writes a VirtualHost with DocumentRoot = <repo>/public and
#      Alias /admin -> <repo>/admin (mirrors router.php).
#   3. Enables the site, runs `apache2ctl configtest`, reloads Apache.
#   4. Verifies over HTTP (Host: chemheaven.cc) that Apache returns 200 and
#      serves a probe file from the project's public/ directory.
#   Any failure after step 2 rolls the change back so the script can be re-run.
#
# Overridable environment variables:
#   DOMAIN       (default: chemheaven.cc)
#   APP_ROOT     (default: directory containing this script)
#   APACHE_USER  (default: www-data)
#
set -Eeuo pipefail

# ---------------------------------------------------------------- config ----
DOMAIN="${DOMAIN:-chemheaven.cc}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
APP_ROOT="${APP_ROOT:-$SCRIPT_DIR}"
APP_ROOT="$(cd -- "$APP_ROOT" && pwd -P)"
DOC_ROOT="${APP_ROOT}/public"
ADMIN_DIR="${APP_ROOT}/admin"
APACHE_USER="${APACHE_USER:-www-data}"

SITES_AVAILABLE="/etc/apache2/sites-available"
SITE_NAME="${DOMAIN}.conf"
VHOST_FILE="${SITES_AVAILABLE}/${SITE_NAME}"

VERIFY_TIMEOUT=10     # seconds per HTTP request
VERIFY_RETRIES=5      # graceful reloads can take a moment

VHOST_CREATED=0
PROBE_FILE=""
TMP_BODY=""

# --------------------------------------------------------------- logging ----
if [[ -t 1 ]]; then
    C_BLUE=$'\033[1;34m'; C_GREEN=$'\033[1;32m'; C_YELLOW=$'\033[1;33m'
    C_RED=$'\033[1;31m'; C_RESET=$'\033[0m'
else
    C_BLUE=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_RESET=""
fi
log()  { printf '%s[INFO]%s  %s\n' "$C_BLUE"   "$C_RESET" "$*"; }
ok()   { printf '%s[ OK ]%s  %s\n' "$C_GREEN"  "$C_RESET" "$*"; }
warn() { printf '%s[WARN]%s  %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '%s[FAIL]%s  %s\n' "$C_RED"    "$C_RESET" "$*" >&2; exit 1; }

# --------------------------------------------------------------- helpers ----
reload_apache() {
    if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
        if systemctl is-active --quiet apache2; then
            systemctl reload apache2
        else
            systemctl start apache2
        fi
    else
        if service apache2 status >/dev/null 2>&1; then
            service apache2 reload
        else
            service apache2 start
        fi
    fi
}

as_apache_user() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$APACHE_USER" -- "$@"
    else
        sudo -u "$APACHE_USER" -- "$@"
    fi
}

# http_get <path>  -> prints HTTP status code; body is written to $TMP_BODY
http_get() {
    local code
    code="$(curl -s -o "$TMP_BODY" -w '%{http_code}' \
                 --max-time "$VERIFY_TIMEOUT" \
                 -H "Host: ${DOMAIN}" \
                 "http://127.0.0.1${1}" 2>/dev/null)" || true
    printf '%s' "${code:-000}"
}

cleanup() {
    local rc=$?
    [[ -n "$PROBE_FILE" && -f "$PROBE_FILE" ]] && rm -f -- "$PROBE_FILE"
    [[ -n "$TMP_BODY"   && -f "$TMP_BODY"   ]] && rm -f -- "$TMP_BODY"

    if (( rc != 0 && VHOST_CREATED == 1 )); then
        warn "Rolling back: disabling and removing ${VHOST_FILE}"
        a2dissite -q "$SITE_NAME" >/dev/null 2>&1 || true
        rm -f -- "$VHOST_FILE"
        if apache2ctl configtest >/dev/null 2>&1; then
            reload_apache >/dev/null 2>&1 || true
        fi
        warn "Rollback complete. Fix the problem above and re-run the script."
    fi
    exit "$rc"
}
trap cleanup EXIT

# ------------------------------------------------------------ pre-flight ----
[[ $EUID -eq 0 ]] || die "Must run as root. Try: sudo $0"

for cmd in apache2ctl a2ensite a2dissite a2enmod curl; do
    command -v "$cmd" >/dev/null 2>&1 \
        || die "Required command '$cmd' not found. Install with: apt-get install -y apache2 curl"
done
[[ -d "$SITES_AVAILABLE" ]] || die "$SITES_AVAILABLE not found — is this a Debian/Ubuntu Apache install?"
id "$APACHE_USER" >/dev/null 2>&1 || die "Apache user '$APACHE_USER' does not exist (set APACHE_USER=...)."

# ---- Step 1: idempotency guard ------------------------------------------
if [[ -e "$VHOST_FILE" ]]; then
    ok "${DOMAIN} is already configured (${VHOST_FILE} exists). Nothing to do."
    exit 0
fi

domain_re="${DOMAIN//./\\.}"
if apache2ctl -S 2>/dev/null | grep -Eq "(namevhost|alias) ${domain_re}( |$)"; then
    ok "${DOMAIN} is already served by another enabled VirtualHost:"
    apache2ctl -S 2>/dev/null | grep -E "(namevhost|alias) ${domain_re}( |$)" | sed 's/^/        /'
    ok "Leaving existing configuration untouched."
    exit 0
fi

# ---- Project sanity checks ----------------------------------------------
[[ -d "$DOC_ROOT" ]]           || die "Web root not found: $DOC_ROOT (set APP_ROOT to the repo path)."
[[ -f "$DOC_ROOT/index.php" ]] || die "$DOC_ROOT/index.php not found — is APP_ROOT the store repository?"

if ! as_apache_user test -r "$DOC_ROOT/index.php"; then
    die "User '$APACHE_USER' cannot read $DOC_ROOT/index.php.
       A parent directory is probably not traversable (common under /root or /home/<user>).
       Inspect with:  namei -l '$DOC_ROOT/index.php'
       Fix by moving the repo under /var/www, or granting execute on each parent dir."
fi

HAVE_ADMIN=0
if [[ -d "$ADMIN_DIR" ]]; then
    HAVE_ADMIN=1
else
    warn "No admin/ directory found; skipping /admin Alias."
fi

[[ -f "$APP_ROOT/vendor/autoload.php" ]] || warn "vendor/autoload.php missing — run 'composer install --no-dev' in $APP_ROOT."
[[ -f "$APP_ROOT/.env" ]]               || warn ".env missing — copy .env.example to .env and fill in DB/OxaPay values."

# PHP handler: mod_php, or php-fpm via proxy_fcgi
if ! apache2ctl -M 2>/dev/null | grep -Eq 'php[0-9._]*_module' \
   && ! { apache2ctl -M 2>/dev/null | grep -q proxy_fcgi_module \
          && compgen -G "/etc/apache2/conf-enabled/php*-fpm.conf" >/dev/null; }; then
    warn "No PHP handler detected in Apache (mod_php or php-fpm). PHP pages will not execute."
    warn "Install one, e.g.: apt-get install -y libapache2-mod-php php-mysql php-curl"
fi

a2enmod -q alias dir >/dev/null

# ---- Step 2: write the VirtualHost --------------------------------------
log "Creating ${VHOST_FILE}"
log "  DocumentRoot: ${DOC_ROOT}"

ADMIN_BLOCK=""
if (( HAVE_ADMIN )); then
    ADMIN_BLOCK="
    # Admin panel lives outside the public webroot (see router.php).
    # Protect it before going live, e.g. restrict by IP:
    #   Require ip 203.0.113.10
    Alias /admin \"${ADMIN_DIR}\"
    <Directory \"${ADMIN_DIR}\">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex products.php
    </Directory>
"
fi

umask 022
cat > "$VHOST_FILE" <<EOF
# Managed by setup-apache.sh — generated $(date -u '+%Y-%m-%d %H:%M:%S UTC')
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAlias www.${DOMAIN}
    ServerAdmin webmaster@${DOMAIN}

    DocumentRoot "${DOC_ROOT}"

    <Directory "${DOC_ROOT}">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>
${ADMIN_BLOCK}
    # Never serve dotfiles (.env, .git, .htaccess, ...)
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}_access.log combined
</VirtualHost>
EOF
VHOST_CREATED=1
chmod 0644 "$VHOST_FILE"
ok "VirtualHost file written."

# ---- Step 3: enable, test, reload ---------------------------------------
log "Enabling site ${SITE_NAME}"
a2ensite -q "$SITE_NAME" >/dev/null

log "Testing Apache configuration"
if ! configtest_out="$(apache2ctl configtest 2>&1)"; then
    printf '%s\n' "$configtest_out" >&2
    die "apache2ctl configtest failed."
fi
ok "Configuration syntax OK."

log "Reloading Apache"
reload_apache || die "Apache failed to reload/start. Check: journalctl -u apache2 -n 50"
ok "Apache reloaded."

# ---- Step 4: verification -----------------------------------------------
TMP_BODY="$(mktemp)"
TOKEN="vhost-probe-$(date +%s)-${RANDOM}${RANDOM}"
PROBE_NAME="__${TOKEN}.txt"
PROBE_FILE="${DOC_ROOT}/${PROBE_NAME}"
printf '%s\n' "$TOKEN" > "$PROBE_FILE"
chmod 0644 "$PROBE_FILE"

log "Verifying: GET http://127.0.0.1/${PROBE_NAME}  (Host: ${DOMAIN})"
code="000"
for (( i = 1; i <= VERIFY_RETRIES; i++ )); do
    code="$(http_get "/${PROBE_NAME}")"
    if [[ "$code" == "200" ]] && grep -qF "$TOKEN" "$TMP_BODY"; then
        break
    fi
    sleep 1
done

if [[ "$code" != "200" ]]; then
    warn "Last lines of ${DOMAIN}_error.log:"
    tail -n 10 "/var/log/apache2/${DOMAIN}_error.log" 2>/dev/null | sed 's/^/        /' >&2 || true
    die "Expected HTTP 200 for the probe file, got ${code}."
fi
if ! grep -qF "$TOKEN" "$TMP_BODY"; then
    die "Got HTTP 200 but the body did not match — another VirtualHost is answering for ${DOMAIN}."
fi
ok "HTTP 200 — Apache is serving files from ${DOC_ROOT}."
rm -f -- "$PROBE_FILE"; PROBE_FILE=""

# Application smoke test (informational only: depends on DB, .env, composer)
log "Checking application front page: GET /  (Host: ${DOMAIN})"
app_code="$(http_get "/")"
if [[ "$app_code" == "200" ]]; then
    if grep -q '<?php' "$TMP_BODY"; then
        warn "Front page returned raw PHP source — PHP is not enabled in Apache."
    else
        ok "Front page returned HTTP 200."
    fi
else
    warn "Front page returned HTTP ${app_code}. The vhost works; the app likely still needs"
    warn ".env, 'composer install', and the database schema (see README)."
fi

cat <<EOF

${C_GREEN}Done.${C_RESET} ${DOMAIN} is configured and enabled.
  VirtualHost : ${VHOST_FILE}
  Web root    : ${DOC_ROOT}
  Logs        : /var/log/apache2/${DOMAIN}_{access,error}.log

Next steps:
  * Point DNS A/AAAA records for ${DOMAIN} and www.${DOMAIN} at this server.
  * Add HTTPS:  apt-get install -y certbot python3-certbot-apache
                certbot --apache -d ${DOMAIN} -d www.${DOMAIN}
  * Restrict /admin before going live (see comment in the vhost file).
EOF
