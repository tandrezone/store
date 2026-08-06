<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\PageView;
use Store\Services\AdminAuth;

AdminAuth::requireAuth();

const DAY_RANGES = [7, 30, 90];

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, DAY_RANGES, true)) {
    $days = 30;
}

$summary = PageView::summary($days);
$daily = PageView::dailyVisits($days);
$topProducts = PageView::topProducts($days, 10);
$topPaths = PageView::topPaths($days, 8);

$visited = $summary['visit']['unique_sessions'];
$reachedShipping = $summary['checkout_shipping']['unique_sessions'];
$reachedPayment = $summary['checkout_payment']['unique_sessions'];

$funnelSteps = [
    ['label' => 'Visited site', 'count' => $visited],
    ['label' => 'Reached shipping', 'count' => $reachedShipping],
    ['label' => 'Reached payment', 'count' => $reachedPayment],
];
$funnelMax = max(1, $visited);

$pageTitle = 'Admin — Analytics';
$activeNav = 'analytics';
require __DIR__ . '/partials/header.php';
?>

<h1>Analytics</h1>

<div class="filters">
    <?php foreach (DAY_RANGES as $range): ?>
        <a href="/admin/analytics.php?days=<?= $range ?>" class="filter-pill <?= $days === $range ? 'active' : '' ?>">
            <?= $range ?> days
        </a>
    <?php endforeach; ?>
</div>

<div class="stat-grid">
    <div class="stat-tile">
        <span class="stat-label">Site visits</span>
        <span class="stat-value"><?= number_format($summary['visit']['total']) ?></span>
        <span class="stat-sub"><?= number_format($visited) ?> unique visitors</span>
    </div>
    <div class="stat-tile">
        <span class="stat-label">Product views</span>
        <span class="stat-value"><?= number_format($summary['product_view']['total']) ?></span>
        <span class="stat-sub"><?= number_format($summary['product_view']['unique_sessions']) ?> unique visitors</span>
    </div>
    <div class="stat-tile">
        <span class="stat-label">Reached shipping</span>
        <span class="stat-value"><?= number_format($reachedShipping) ?></span>
        <span class="stat-sub">visitors</span>
    </div>
    <div class="stat-tile">
        <span class="stat-label">Reached payment</span>
        <span class="stat-value"><?= number_format($reachedPayment) ?></span>
        <span class="stat-sub">visitors</span>
    </div>
    <div class="stat-tile">
        <span class="stat-label">Visit → shipping</span>
        <span class="stat-value"><?= $visited > 0 ? round($reachedShipping / $visited * 100) : 0 ?>%</span>
        <span class="stat-sub">conversion</span>
    </div>
    <div class="stat-tile">
        <span class="stat-label">Shipping → payment</span>
        <span class="stat-value"><?= $reachedShipping > 0 ? round($reachedPayment / $reachedShipping * 100) : 0 ?>%</span>
        <span class="stat-sub">conversion</span>
    </div>
</div>

<div class="panel">
    <h2>Checkout funnel</h2>
    <div class="funnel">
        <?php foreach ($funnelSteps as $i => $step): ?>
            <?php $pct = $funnelMax > 0 ? round($step['count'] / $funnelMax * 100) : 0; ?>
            <div class="funnel-row">
                <span class="funnel-label"><?= htmlspecialchars($step['label']) ?></span>
                <div class="funnel-bar-track">
                    <div class="funnel-bar funnel-step-<?= $i + 1 ?>" style="width: <?= max($pct, 2) ?>%;"
                         tabindex="0" title="<?= htmlspecialchars($step['label']) ?>: <?= number_format($step['count']) ?> visitors (<?= $pct ?>%)">
                        <span class="funnel-value"><?= number_format($step['count']) ?></span>
                    </div>
                </div>
                <span class="funnel-pct"><?= $pct ?>%</span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel">
    <h2>Daily traffic</h2>
    <div class="chart-legend">
        <span class="legend-item"><span class="legend-swatch legend-swatch-line legend-teal"></span>Total visits</span>
        <span class="legend-item"><span class="legend-swatch legend-swatch-line legend-violet"></span>Unique visitors</span>
    </div>
    <div class="chart-wrap" id="visits-chart" data-points='<?= htmlspecialchars(json_encode($daily), ENT_QUOTES) ?>'></div>
    <details class="chart-table-toggle">
        <summary>View as table</summary>
        <table class="cart-table admin-table">
            <thead><tr><th>Day</th><th class="tabular">Total visits</th><th class="tabular">Unique visitors</th></tr></thead>
            <tbody>
                <?php foreach ($daily as $point): ?>
                    <tr>
                        <td><?= htmlspecialchars($point['day']) ?></td>
                        <td class="tabular"><?= number_format($point['total']) ?></td>
                        <td class="tabular"><?= number_format($point['unique_sessions']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</div>

<div class="analytics-grid-2col">
    <div class="panel">
        <h2>Most viewed products</h2>
        <table class="cart-table admin-table">
            <thead><tr><th>Product</th><th class="tabular">Views</th><th class="tabular">Unique visitors</th></tr></thead>
            <tbody>
                <?php if (empty($topProducts)): ?>
                    <tr><td colspan="3" style="color: var(--color-muted);">No product views yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($topProducts as $row): ?>
                    <tr>
                        <td><a href="/admin/products.php?edit=<?= (int) $row['product_id'] ?>"><?= htmlspecialchars($row['name']) ?></a></td>
                        <td class="tabular"><?= number_format((int) $row['views']) ?></td>
                        <td class="tabular"><?= number_format((int) $row['unique_sessions']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h2>Most visited pages</h2>
        <table class="cart-table admin-table">
            <thead><tr><th>Page</th><th class="tabular">Visits</th><th class="tabular">Unique visitors</th></tr></thead>
            <tbody>
                <?php if (empty($topPaths)): ?>
                    <tr><td colspan="3" style="color: var(--color-muted);">No visits yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($topPaths as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['path']) ?></td>
                        <td class="tabular"><?= number_format((int) $row['total']) ?></td>
                        <td class="tabular"><?= number_format((int) $row['unique_sessions']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="/assets/js/analytics.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
