<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Order;

// OxaPay appends identifying params to the return_url; adjust the param
// name here once you confirm exactly what OxaPay sends back to return_url.
$orderNumber = trim((string) ($_GET['order_id'] ?? $_GET['order'] ?? ''));
$order = $orderNumber !== '' ? Order::findByNumber($orderNumber) : null;

$pageTitle = 'Order Confirmation';
require __DIR__ . '/partials/header.php';
?>

<section class="confirmation-page">
    <?php if ($order): ?>
        <h1>Thank you, your order is on its way to being processed.</h1>
        <p>Order number: <strong><?= htmlspecialchars($order['order_number']) ?></strong></p>
        <p>Payment status: <strong><?= htmlspecialchars($order['payment_status']) ?></strong></p>
        <p>A confirmation will be sent to <?= htmlspecialchars($order['email']) ?> once payment is confirmed.</p>
        <?php if ($order['payment_status'] !== 'paid'): ?>
            <p class="field-hint">
                It can take a minute for the blockchain confirmation to arrive. Refresh this page
                or check your order status using the order number and email above.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <h1>Order received</h1>
        <p>We couldn't find that order number, but if you just completed payment, it will be
           confirmed shortly. Keep your order number handy to check its status.</p>
    <?php endif; ?>

    <p><a href="/index.php">Continue shopping</a></p>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
