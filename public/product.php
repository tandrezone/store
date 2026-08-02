<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Product;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$product = $id ? Product::find($id) : null;

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require __DIR__ . '/partials/header.php';
    echo '<p>Sorry, that product could not be found. <a href="/index.php">Back to shop</a></p>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$pageTitle = $product['name'];
require __DIR__ . '/partials/header.php';
?>

<section class="product-detail">
    <div class="product-detail-image">
        <?php if (!empty($product['image_path'])): ?>
            <img src="/<?= htmlspecialchars($product['image_path']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
        <?php else: ?>
            <div class="image-placeholder">No image</div>
        <?php endif; ?>
    </div>

    <div class="product-detail-info">
        <span class="category-badge"><?= htmlspecialchars($product['category_name']) ?></span>
        <h1><?= htmlspecialchars($product['name']) ?></h1>
        <p class="short-desc"><?= htmlspecialchars($product['short_description']) ?></p>

        <form id="add-to-cart-form" data-product-id="<?= (int) $product['id'] ?>">
            <label for="variant-select">Choose an option</label>
            <select id="variant-select" name="variant_id" required>
                <?php foreach ($product['variants'] as $variant): ?>
                    <option value="<?= (int) $variant['id'] ?>"
                            data-price="<?= htmlspecialchars((string) $variant['price']) ?>"
                            <?= $variant['stock'] <= 0 ? 'disabled' : '' ?>>
                        <?= htmlspecialchars(trim($variant['label'] . ' ' . $variant['unit'])) ?> — <?= number_format((float) $variant['price'], 2) ?>€
                        <?= $variant['stock'] <= 0 ? '(out of stock)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="quantity-input">Quantity</label>
            <input type="number" id="quantity-input" name="quantity" value="1" min="1" required>

            <button type="submit" class="btn-primary">Add to cart</button>
            <p id="add-to-cart-message" class="form-message" role="status"></p>
        </form>

        <div class="long-description">
            <h2>Description</h2>
            <p><?= nl2br(htmlspecialchars($product['long_description'])) ?></p>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
