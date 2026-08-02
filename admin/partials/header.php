<?php
/** Expects $pageTitle and optionally $activeNav ('products'|'suppliers'|'orders') to be set by the including page. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Admin') ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="/admin/products.php" class="logo">Store <span class="admin-tag">Admin</span></a>
        <nav class="main-nav">
            <a href="/admin/products.php" class="<?= ($activeNav ?? '') === 'products' ? 'active' : '' ?>">Products</a>
            <a href="/admin/suppliers.php" class="<?= ($activeNav ?? '') === 'suppliers' ? 'active' : '' ?>">Suppliers</a>
            <a href="/admin/orders.php" class="<?= ($activeNav ?? '') === 'orders' ? 'active' : '' ?>">Orders</a>
            <a href="/admin/logout.php">Log out</a>
        </nav>
    </div>
</header>
<main class="container">
