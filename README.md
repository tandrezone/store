# Online Store Scaffold

PHP + MariaDB + vanilla HTML/CSS/JS storefront with category filtering,
a product detail page, a database-backed cart, checkout, and OxaPay
payment integration.

## 1. Requirements

- PHP 8.1+ with `pdo_mysql` and `curl` extensions enabled
- MariaDB 10.x
- A web server (Apache/Nginx) or `php -S` for local testing

## 2. Database setup

```bash
mysql -u root -p < database/schema.sql
```

This creates the `online_store` database, all tables, and a few sample
products so you can browse the storefront immediately.

Create a dedicated DB user for the app rather than using root:

```sql
CREATE USER 'store_user'@'localhost' IDENTIFIED BY 'a_strong_password';
GRANT ALL PRIVILEGES ON online_store.* TO 'store_user'@'localhost';
FLUSH PRIVILEGES;
```

## 3. Environment variables

Set these before running PHP (via your web server config, a `.env`
loader of your choice, or `putenv()` in a bootstrap file):

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MariaDB connection |
| `APP_URL` | Public base URL, e.g. `https://yourstore.com` (used to build OxaPay callback/return URLs) |
| `OXAPAY_MERCHANT_KEY` | Your Merchant API key from the OxaPay dashboard |
| `OXAPAY_SANDBOX` | `true` while testing, `false` when going live |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` | `/admin` login. See below. |

A `.env` file in the project root is loaded automatically (via
`src/bootstrap.php`, wired up through Composer's `files` autoload) —
values already set in the real process environment always take
priority over it. Copy `.env` and fill in your own values; nothing
else needs to source it manually.

### Admin login

The repo ships with a working default so `/admin` isn't locked out of
the box:

- Username: `admin`
- Password: `AdminPass123!`

**Change this before deploying.** Generate a new hash and put it in
`.env`:

```bash
php -r "echo password_hash('your-new-password', PASSWORD_BCRYPT), PHP_EOL;"
```

```
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=<the hash printed above>
```

## 4. Running locally

```bash
cd public
php -S localhost:8000
```

Visit `http://localhost:8000/index.php`.

## 5. Folder structure

```
database/schema.sql        Full DDL + seed data
src/Config/                DB + OxaPay configuration
src/Models/                Product, Category, Variant, Order
src/Services/              CartService, OxaPayClient
public/                    Web root — point your server here
public/index.php           Product listing + category filter
public/product.php         Product detail + add to cart
public/cart.php            Cart view
public/checkout.php        Shipping form -> creates order -> OxaPay invoice
public/payment/callback.php  OxaPay webhook (marks orders paid, decrements stock)
public/order-confirmation.php  Landing page after payment (OxaPay return_url)
public/api/cart.php        AJAX endpoints used by assets/js/app.js
admin/products.php          No-auth product entry screen — protect before deploying
```

## 6. Before going live — checklist

- [ ] Change the default admin password (see "Admin login" above) — it ships with a known default
- [ ] Protect `/admin` (see `admin/.htaccess.example`) or replace it with real auth
- [ ] Re-verify OxaPay's current invoice + webhook contract against
      https://docs.oxapay.com/ — field names and signature verification
      can change between API versions; `src/Services/OxaPayClient.php`
      and `public/payment/callback.php` are the two files to check
- [ ] Add webhook signature verification in `payment/callback.php`
      before trusting a callback
- [ ] Serve everything over HTTPS (required for real webhooks)
- [ ] Add product images to `public/assets/images/` and update
      `image_path` in the `products` table
- [ ] Wire up an actual email send in `payment/callback.php` where the
      `TODO` is, so customers get a confirmation once paid
- [ ] Consider adding customer accounts/login if you want order history
      beyond the "look up by order number + email" flow

## 7. Not yet built (natural next steps)

- Order tracking page (look up by order number + email)
- Admin edit/delete for products, categories, and viewing orders
- Image upload in the admin screen (currently just a path column)
- Pagination on the product listing
