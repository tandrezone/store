# Online Store Scaffold

A PHP + MariaDB storefront with vanilla HTML/CSS/JS, featuring category filtering, a product detail page, a database-backed cart, checkout, and [OxaPay](https://oxapay.com/) payment integration.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Database Setup](#2-database-setup)
3. [Environment Variables](#3-environment-variables)
4. [Running Locally](#4-running-locally)
5. [Folder Structure](#5-folder-structure)
6. [Pre-Deployment Checklist](#6-pre-deployment-checklist)
7. [Roadmap](#7-roadmap)

---

## 1. Requirements

| Dependency | Version |
|---|---|
| PHP | 8.1+ (`pdo_mysql` and `curl` extensions required) |
| MariaDB | 10.x |
| Web server | Apache, Nginx, or `php -S` for local development |

---

## 2. Database Setup

Run the schema file to create the `online_store` database, all tables, and sample products:

```bash
mysql -u root -p < database/schema.sql
```

Create a dedicated database user instead of using root:

```sql
CREATE USER 'store_user'@'localhost' IDENTIFIED BY 'a_strong_password';
GRANT ALL PRIVILEGES ON online_store.* TO 'store_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## 3. Environment Variables

A `.env` file in the project root is loaded automatically via `src/bootstrap.php` (wired through Composer's `files` autoload). Copy `.env.example`, rename it to `.env`, and fill in your values. Any variable already set in the process environment takes priority over the `.env` file.

| Variable | Purpose |
|---|---|
| `DB_HOST` | MariaDB host |
| `DB_PORT` | MariaDB port |
| `DB_NAME` | Database name |
| `DB_USER` | Database username |
| `DB_PASS` | Database password |
| `APP_URL` | Public base URL (e.g. `https://yourstore.com`) — used to build OxaPay callback/return URLs |
| `OXAPAY_MERCHANT_KEY` | Merchant API key from the OxaPay dashboard |
| `OXAPAY_SANDBOX` | `true` while testing, `false` in production |
| `ADMIN_USERNAME` | Admin panel username |
| `ADMIN_PASSWORD_HASH` | Bcrypt hash of the admin password |

### Default Admin Credentials

The repo ships with working defaults so `/admin` is accessible out of the box:

- **Username:** `admin`
- **Password:** `AdminPass123!`

> ⚠️ **Change this before deploying to production.**

Generate a new password hash:

```bash
php -r "echo password_hash('your-new-password', PASSWORD_BCRYPT), PHP_EOL;"
```

Then update `.env`:

```env
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=<the hash printed above>
```

---

## 4. Running Locally

```bash
cd public
php -S localhost:8000
```

Open `http://localhost:8000/index.php` in your browser.

---

## 5. Folder Structure

```
database/
  schema.sql                    Full DDL + seed data

src/
  Config/                       DB + OxaPay configuration
  Models/                       Product, Category, Variant, Order
  Services/                     CartService, OxaPayClient

public/                         Web root — point your server here
  index.php                     Product listing + category filter
  product.php                   Product detail + add to cart
  cart.php                      Cart view
  checkout.php                  Shipping form → creates order → OxaPay invoice
  order-confirmation.php        Landing page after payment (OxaPay return_url)
  payment/
    callback.php                OxaPay webhook (marks orders paid, decrements stock)
  api/
    cart.php                    AJAX endpoints used by assets/js/app.js

admin/
  products.php                  Product management screen (protect before deploying)
```

---

## 6. Pre-Deployment Checklist

- [ ] **Change the default admin password** — see [Default Admin Credentials](#default-admin-credentials)
- [ ] **Protect `/admin`** — use `admin/.htaccess.example` or replace with proper authentication
- [ ] **Verify OxaPay API contract** — field names and signature verification can change between versions; check `src/Services/OxaPayClient.php` and `public/payment/callback.php` against the [OxaPay docs](https://docs.oxapay.com/)
- [ ] **Add webhook signature verification** in `payment/callback.php` before trusting any callback
- [ ] **Serve over HTTPS** — required for production webhooks
- [ ] **Add product images** to `public/assets/images/` and update the `image_path` column in the `products` table
- [ ] **Wire up email confirmation** in `payment/callback.php` at the existing `TODO` so customers receive a receipt after payment
- [ ] **Consider customer accounts** if you need order history beyond the order-number + email lookup flow

---

## 7. Roadmap

- Order tracking page (look up by order number + email)
- Admin screens for editing/deleting products, categories, and viewing orders
- Image upload in the admin panel (currently accepts a file path only)
- Pagination on the product listing
