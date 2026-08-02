<?php
/**
 * Dev-server router: replicates the production Apache setup, where
 * DocumentRoot is public/ and /admin is a separate Alias pointing at
 * the sibling admin/ directory (kept outside the public webroot).
 *
 * Usage: php -S localhost:8000 -t public router.php
 */

$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (str_starts_with($uri, '/admin/')) {
    $file = __DIR__ . $uri;

    if (is_file($file)) {
        chdir(dirname($file));
        require $file;
        return true;
    }
}

return false;
