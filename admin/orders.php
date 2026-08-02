<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Order;
use Store\Services\AdminAuth;

AdminAuth::requireAuth();

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($statusFilter, Order::STATUSES, true)) {
    $statusFilter = '';
}

$orders = Order::allByStatus($statusFilter !== '' ? $statusFilter : null);

$pageTitle = 'Admin — Orders';
$activeNav = 'orders';
require __DIR__ . '/partials/header.php';
?>

<h1>Orders</h1>

<div class="filters">
    <a href="/admin/orders.php" class="filter-pill <?= $statusFilter === '' ? 'active' : '' ?>">All</a>
    <?php foreach (Order::STATUSES as $status): ?>
        <a href="/admin/orders.php?status=<?= htmlspecialchars($status) ?>"
           class="filter-pill <?= $statusFilter === $status ? 'active' : '' ?>">
            <?= htmlspecialchars(ucfirst($status)) ?>
        </a>
    <?php endforeach; ?>
</div>

<table class="cart-table">
    <thead>
        <tr>
            <th>Order #</th>
            <th>Customer</th>
            <th>Status</th>
            <th>Payment</th>
            <th>Total</th>
            <th>Placed</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($orders)): ?>
            <tr><td colspan="7" style="color: var(--color-muted);">No orders found.</td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><?= htmlspecialchars($order['order_number']) ?></td>
                <td>
                    <?= htmlspecialchars($order['ship_name']) ?><br>
                    <span style="color: var(--color-muted); font-size: 0.85rem;"><?= htmlspecialchars($order['email']) ?></span>
                </td>
                <td><span class="status-badge" data-status="<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span></td>
                <td><span class="status-badge" data-status="<?= htmlspecialchars($order['payment_status']) ?>"><?= htmlspecialchars($order['payment_status']) ?></span></td>
                <td>$<?= number_format((float) $order['total'], 2) ?></td>
                <td style="color: var(--color-muted); font-size: 0.85rem;"><?= htmlspecialchars((string) $order['created_at']) ?></td>
                <td><a href="/admin/order.php?id=<?= (int) $order['id'] ?>" class="btn-secondary">Open</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/partials/footer.php'; ?>
