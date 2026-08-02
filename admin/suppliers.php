<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Supplier;
use Store\Services\AdminAuth;
use Store\Services\ProductImporter;

AdminAuth::requireAuth();

$message = null;
$importSummary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $url = trim((string) ($_POST['list_products_url'] ?? ''));

        if ($name !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            Supplier::create($name, $url);
            $message = "Supplier \"{$name}\" added.";
        } else {
            $message = 'Please provide a name and a valid URL.';
        }
    } elseif ($action === 'delete') {
        Supplier::delete((int) ($_POST['id'] ?? 0));
        $message = 'Supplier removed.';
    } elseif ($action === 'run_import') {
        $importSummary = ProductImporter::runAll();
        $message = 'Import finished.';
    }
}

$suppliers = Supplier::all();

$pageTitle = 'Admin — Suppliers';
$activeNav = 'suppliers';
require __DIR__ . '/partials/header.php';
?>

<h1>Suppliers</h1>

<?php if ($message): ?><p class="msg"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if ($importSummary !== null): ?>
    <div class="panel">
        <h2>Import results</h2>
        <ul class="order-items-list">
            <?php foreach ($importSummary as $row): ?>
                <li>
                    <span>
                        <?= htmlspecialchars($row['supplier']) ?>
                        <?php if (!empty($row['errors'])): ?>
                            <br><span style="color: var(--color-danger); font-size: 0.85rem;">
                                <?= htmlspecialchars(implode('; ', $row['errors'])) ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span>
                        fetched <?= (int) $row['fetched'] ?> ·
                        imported <?= (int) $row['imported'] ?> ·
                        updated <?= (int) $row['updated'] ?> ·
                        skipped <?= (int) $row['skipped'] ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="panel">
    <h2>Add supplier</h2>
    <form method="post">
        <label>Name <input type="text" name="name" required></label>
        <label>Product list URL <input type="url" name="list_products_url" placeholder="https://supplier.example.com/products.json" required></label>
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn-primary">Add supplier</button>
    </form>
</div>

<h2>Existing suppliers</h2>
<table class="cart-table">
    <thead><tr><th>Name</th><th>List URL</th><th></th></tr></thead>
    <tbody>
        <?php foreach ($suppliers as $sup): ?>
            <tr>
                <td><?= htmlspecialchars($sup['name']) ?></td>
                <td><?= htmlspecialchars($sup['list_products_url']) ?></td>
                <td>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $sup['id'] ?>">
                        <button type="submit" class="btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<form method="post" style="margin-top: 20px;">
    <input type="hidden" name="action" value="run_import">
    <button type="submit" class="btn-primary">Run import now</button>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
