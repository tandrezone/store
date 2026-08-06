<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Config\OxaPayConfig;
use Store\Models\Order;
use Store\Models\PageView;
use Store\Services\CartService;
use Store\Services\OxaPayClient;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$cart = new CartService();
$items = $cart->getItems();
$errors = [];

if (empty($items)) {
    header('Location: /cart.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    PageView::record('checkout_shipping', null, '/checkout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Your session expired, please try again.';
    }

    $shipping = [
        'name'        => trim((string) ($_POST['name'] ?? '')),
        'email'       => trim((string) ($_POST['email'] ?? '')),
        'phone'       => trim((string) ($_POST['phone'] ?? '')),
        'address1'    => trim((string) ($_POST['address1'] ?? '')),
        'address2'    => trim((string) ($_POST['address2'] ?? '')),
        'city'        => trim((string) ($_POST['city'] ?? '')),
        'state'       => trim((string) ($_POST['state'] ?? '')),
        'postal_code' => trim((string) ($_POST['postal_code'] ?? '')),
        'country'     => trim((string) ($_POST['country'] ?? '')),
    ];

    $shippingMethod = (string) ($_POST['shipping_method'] ?? '');

    if ($shipping['name'] === '') $errors[] = 'Full name is required.';
    if (!filter_var($shipping['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($shipping['address1'] === '') $errors[] = 'Address is required.';
    if ($shipping['city'] === '') $errors[] = 'City is required.';
    if ($shipping['postal_code'] === '') $errors[] = 'Postal code is required.';
    if ($shipping['country'] === '') $errors[] = 'Country is required.';
    if (!isset(Order::SHIPPING_METHODS[$shippingMethod])) $errors[] = 'Please choose a shipping method.';

    if (empty($errors)) {
        try {
            $order = Order::create($shipping, $items, $shippingMethod);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $client = new OxaPayClient(OxaPayConfig::get());

        try {
            $invoice = $client->createInvoice(
                $order['order_number'],
                (float) $order['total'],
                $shipping['email']
            );

            if (!empty($invoice['data']['payment_url'])) {
                $cart->clear();
                PageView::record('checkout_payment', null, '/checkout.php');
                header('Location: ' . $invoice['data']['payment_url']);
                exit;
            }

            $errors[] = 'Could not start the payment. Please try again shortly.';
        } catch (Throwable $e) {
            $errors[] = 'Payment provider error: ' . $e->getMessage();
        }
    }
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));

$pageTitle = 'Checkout';
require __DIR__ . '/partials/header.php';
?>

<section class="checkout-page">
    <h1>Checkout</h1>

    <?php if (!empty($errors)): ?>
        <div class="form-errors">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="checkout-grid">
        <form method="post" class="shipping-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label for="name">Full name</label>
            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            <p class="field-hint">Used for order tracking updates.</p>

            <label for="phone">Phone (optional)</label>
            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">

            <label for="address1">Address</label>
            <input type="text" id="address1" name="address1" required value="<?= htmlspecialchars($_POST['address1'] ?? '') ?>">

            <label for="address2">Address line 2 (optional)</label>
            <input type="text" id="address2" name="address2" value="<?= htmlspecialchars($_POST['address2'] ?? '') ?>">

            <div class="form-row">
                <div>
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" required value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                </div>
                <div>
                    <label for="state">State / Province</label>
                    <input type="text" id="state" name="state" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="postal_code">Postal code</label>
                    <input type="text" id="postal_code" name="postal_code" required value="<?= htmlspecialchars($_POST['postal_code'] ?? '') ?>">
                </div>
                <div>
                    <label for="country">Country</label>
                    <input type="text" id="country" name="country" required value="<?= htmlspecialchars($_POST['country'] ?? '') ?>">
                </div>
            </div>

            <h2>Shipping method</h2>
            <?php $selectedMethod = $_POST['shipping_method'] ?? 'standard'; ?>
            <div class="shipping-methods">
                <?php foreach (Order::SHIPPING_METHODS as $key => $method): ?>
                    <label class="shipping-method-option">
                        <input type="radio" name="shipping_method" value="<?= htmlspecialchars($key) ?>"
                               data-cost="<?= htmlspecialchars((string) $method['cost']) ?>"
                               <?= $selectedMethod === $key ? 'checked' : '' ?> required>
                        <span><?= htmlspecialchars($method['label']) ?></span>
                        <span class="shipping-method-cost">
                            <?= $method['cost'] > 0 ? number_format($method['cost'], 2) . '€' : 'Free' ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn-primary">Continue to payment</button>
        </form>

        <aside class="order-summary">
            <h2>Order summary</h2>
            <ul>
                <?php foreach ($items as $item): ?>
                    <li>
                        <?= htmlspecialchars($item['product_name']) ?>
                        (<?= htmlspecialchars(trim($item['label'] . ' ' . $item['unit'])) ?>) &times; <?= (int) $item['quantity'] ?>
                        <span class="line-price">
                            <?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?>€
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="order-subtotal"><span>Subtotal</span><span><?= number_format($cart->getTotal($items), 2) ?>€</span></p>
            <p class="order-shipping"><span>Shipping</span><span id="checkout-shipping-cost"><?= number_format((float) (Order::SHIPPING_METHODS[$selectedMethod]['cost'] ?? 0), 2) ?>€</span></p>
            <p class="order-total"><span>Total</span><span id="checkout-total"><?= number_format($cart->getTotal($items) + (float) (Order::SHIPPING_METHODS[$selectedMethod]['cost'] ?? 0), 2) ?>€</span></p>
            <p class="payment-note">You'll be redirected to OxaPay to complete payment.</p>
        </aside>
    </div>
</section>

<script>
(function () {
    var subtotal = <?= json_encode($cart->getTotal($items)) ?>;
    var shippingEl = document.getElementById('checkout-shipping-cost');
    var totalEl = document.getElementById('checkout-total');

    document.querySelectorAll('input[name="shipping_method"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var cost = parseFloat(radio.dataset.cost) || 0;
            shippingEl.textContent = cost.toFixed(2) + '€';
            totalEl.textContent = (subtotal + cost).toFixed(2) + '€';
        });
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
