<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Order;
use Store\Services\AdminAuth;

AdminAuth::requireAuth();

$id = (int) ($_GET['id'] ?? 0);
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $postedId = (int) ($_POST['id'] ?? 0);
    if ($postedId === $id && Order::updateStatus($id, (string) ($_POST['status'] ?? ''))) {
        $message = 'Status updated.';
    }
}

$order = $id > 0 ? Order::find($id) : null;

$pageTitle = $order ? 'Order ' . $order['order_number'] : 'Order not found';
$activeNav = 'orders';
require __DIR__ . '/partials/header.php';
?>

<p><a href="/admin/orders.php" class="btn-secondary">&larr; Back to orders</a></p>

<?php if (!$order): ?>
    <p class="msg error">Order not found.</p>
<?php else: ?>

    <h1>
        <?= htmlspecialchars($order['order_number']) ?>
        <span class="status-badge" data-status="<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span>
        <span class="status-badge" data-status="<?= htmlspecialchars($order['payment_status']) ?>"><?= htmlspecialchars($order['payment_status']) ?></span>
    </h1>
    <p style="color: var(--color-muted);">Placed <?= htmlspecialchars((string) $order['created_at']) ?></p>

    <?php if ($message): ?><p class="msg"><?= htmlspecialchars($message) ?></p><?php endif; ?>

    <div class="grid-2">
        <div class="panel">
            <h2>Items</h2>
            <ul class="order-items-list">
                <?php foreach ($order['items'] as $item): ?>
                    <li>
                        <span><?= htmlspecialchars($item['product_name']) ?> — <?= htmlspecialchars((string) $item['pack_qty']) ?> &times; <?= (int) $item['quantity'] ?></span>
                        <span>$<?= number_format((float) $item['line_total'], 2) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="display: flex; justify-content: space-between; margin-top: 16px;">
                <span>Subtotal</span>
                <span><?= number_format((float) $order['subtotal'], 2) ?>€</span>
            </p>
            <p style="display: flex; justify-content: space-between;" class="order-total">
                <span>Total</span>
                <span><?= number_format((float) $order['total'], 2) ?>€</span>
            </p>
        </div>

        <div>
            <div class="panel">
                <h2>Customer</h2>
                <div class="address-block">
                    <span class="label">Contact</span><br>
                    <?= htmlspecialchars($order['email']) ?><br>
                    <?= htmlspecialchars($order['phone'] ?? '—') ?>
                    <br><br>
                    <span class="label">Shipping address</span><br>
                    <?= htmlspecialchars($order['ship_name']) ?><br>
                    <?= htmlspecialchars($order['ship_address1']) ?><br>
                    <?php if (!empty($order['ship_address2'])): ?><?= htmlspecialchars($order['ship_address2']) ?><br><?php endif; ?>
                    <?= htmlspecialchars($order['ship_city']) ?><?= !empty($order['ship_state']) ? ', ' . htmlspecialchars($order['ship_state']) : '' ?> <?= htmlspecialchars($order['ship_postal_code']) ?><br>
                    <?= htmlspecialchars($order['ship_country']) ?>
                </div>
            </div>

            <div class="panel">
                <h2>Update status</h2>
                <form method="post">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <select name="status" class="status-select" style="width: 100%; margin-bottom: 12px;">
                        <?php foreach (Order::STATUSES as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary">Save status</button>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
