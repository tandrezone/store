<?php
/** Expects $pageTitle to be set by the including page. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Store') ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="/index.php" class="logo">Store</a>
        <nav class="main-nav">
            <a href="/index.php">Shop</a>
            <a href="/cart.php" class="cart-link">
                Cart <span id="cart-count" class="cart-count">0</span>
            </a>
        </nav>
    </div>
</header>
<main class="container">
