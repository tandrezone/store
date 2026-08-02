<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Services\CartService;

$cart = new CartService();
$items = $cart->getItems();
$total = $cart->getTotal();

$pageTitle = 'Your Cart';
require __DIR__ . '/partials/header.php';
?>

<section class="cart-page">
    <h1>Your Cart</h1>

    <?php if (empty($items)): ?>
        <p>Your cart is empty. <a href="/index.php">Continue shopping</a>.</p>
    <?php else: ?>
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Pack</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Subtotal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cart-items-body">
                <?php foreach ($items as $item): ?>
                    <tr data-variant-id="<?= (int) $item['variant_id'] ?>">
                        <td>
                            <a href="/product.php?id=<?= (int) $item['product_id'] ?>">
                                <?= htmlspecialchars($item['product_name']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars(trim($item['label'] . ' ' . $item['unit'])) ?></td>
                        <td><?= number_format((float) $item['price'], 2) ?>€</td>
                        <td>
                            <input type="number" class="cart-qty-input" min="1"
                                   value="<?= (int) $item['quantity'] ?>"
                                   data-variant-id="<?= (int) $item['variant_id'] ?>">
                        </td>
                        <td class="line-subtotal">
                            <?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?>€
                        </td>
                        <td>
                            <button class="cart-remove-btn" data-variant-id="<?= (int) $item['variant_id'] ?>">
                                Remove
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="cart-summary">
            <p class="cart-total">Total: <span id="cart-total"><?= number_format($total, 2) ?></span>€</p>
            <a href="/checkout.php" class="btn-primary">Proceed to checkout</a>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
