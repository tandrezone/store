<?php
/** Expects $pageTitle to be set by the including page. */

use Store\Models\PageView;

PageView::record('visit', null, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'ChemHeaven') ?></title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
<link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="/index.php" class="logo">ChemHeaven</a>
        <nav class="main-nav">
            <a href="/index.php">Shop</a>
            <a href="/cart.php" class="cart-link">
                Cart <span id="cart-count" class="cart-count">0</span>
            </a>
        </nav>
    </div>
</header>
<main class="container">
