<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Category;
use Store\Models\Product;

$categorySlug = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
$categories = Category::all();
$products = Product::allByCategory($categorySlug);

$pageTitle = 'Shop';
require __DIR__ . '/partials/header.php';
?>

<section class="shop-hero">
    <div class="shop-hero-inner">
        <span class="hero-eyebrow">Est. underground</span>
        <h1>The Lab Collection</h1>
        <p>Small-batch goods, brewed in the dark and built to glow.</p>
    </div>
</section>

<section class="filters">
    <a href="/index.php" class="filter-pill <?= $categorySlug === null ? 'active' : '' ?>">All</a>
    <?php foreach ($categories as $cat): ?>
        <a href="/index.php?category=<?= urlencode($cat['slug']) ?>"
           class="filter-pill <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>">
            <?= htmlspecialchars($cat['name']) ?>
        </a>
    <?php endforeach; ?>
</section>

<section class="product-grid">
    <?php if (empty($products)): ?>
        <p>No products found in this category yet.</p>
    <?php endif; ?>

    <?php foreach ($products as $product): ?>
        <a class="product-card" href="/product.php?id=<?= (int) $product['id'] ?>">
            <div class="product-card-image">
                <?php if (!empty($product['image_path'])): ?>
                    <img src="/<?= htmlspecialchars($product['image_path']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                <?php else: ?>
                    <div class="image-placeholder">No image</div>
                <?php endif; ?>
            </div>
            <div class="product-card-body">
                <span class="category-badge"><?= htmlspecialchars($product['category_name']) ?></span>
                <h3><?= htmlspecialchars($product['name']) ?></h3>
                <p class="short-desc"><?= htmlspecialchars($product['short_description']) ?></p>
                <?php if ($product['from_price'] !== null): ?>
                    <p class="price">From <?= number_format((float) $product['from_price'], 2)?>€</p>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
