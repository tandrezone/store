<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Config\Database;
use Store\Models\Category;
use Store\Models\Product;
use Store\Models\Supplier;
use Store\Models\Variant;
use Store\Services\AdminAuth;
use Store\Services\Csrf;
use Store\Services\ProductUpdater;

AdminAuth::requireAuth();

const IMPORT_STATUSES = ['created', 'imported', 'invalid', 'update', 'approved'];

$pdo = Database::connection();
$message = null;
$messageType = 'success';
$expandedId = (int) ($_GET['edit'] ?? 0);
$updateSummary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'set_status' && $id > 0) {
        $status = (string) ($_POST['status'] ?? '');
        if (in_array($status, IMPORT_STATUSES, true)) {
            $pdo->prepare('UPDATE products SET import_status = :status WHERE id = :id')
                ->execute(['status' => $status, 'id' => $id]);
            $message = 'Status updated.';
        }
    } elseif ($action === 'delete' && $id > 0) {
        try {
            Product::delete($id);
            $message = 'Product deleted.';
        } catch (Throwable $e) {
            $message = 'Could not delete: it is referenced by existing orders.';
            $messageType = 'error';
        }
    } elseif ($action === 'update' && $id > 0) {
        Product::update($id, [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'category_id' => (int) ($_POST['category_id'] ?? 0),
            'supplier_id' => ((int) ($_POST['supplier_id'] ?? 0)) ?: null,
            'short_description' => trim((string) ($_POST['short_description'] ?? '')),
            'long_description' => trim((string) ($_POST['long_description'] ?? '')),
        ]);
        $message = 'Product updated.';
        $expandedId = $id;
    } elseif ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $shortDesc = trim((string) ($_POST['short_description'] ?? ''));
        $longDesc = trim((string) ($_POST['long_description'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $stock = (int) ($_POST['stock'] ?? 0);
        $sku = trim((string) ($_POST['sku'] ?? ''));

        if ($name && $categoryId && $shortDesc && $longDesc && $sku && $price > 0) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO products (category_id, supplier_id, name, short_description, long_description, import_status)
                    VALUES (:category_id, :supplier_id, :name, :short_desc, :long_desc, 'created')
                ");
                $stmt->execute([
                    'category_id' => $categoryId,
                    'supplier_id' => $supplierId > 0 ? $supplierId : null,
                    'name'        => $name,
                    'short_desc'  => $shortDesc,
                    'long_desc'   => $longDesc,
                ]);
                $productId = (int) $pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO product_variants (product_id, sku, label, unit, price, stock)
                    VALUES (:product_id, :sku, :label, :unit, :price, :stock)
                ")->execute([
                    'product_id' => $productId,
                    'sku'        => $sku,
                    'label'      => $label !== '' ? $label : null,
                    'unit'       => $unit !== '' ? $unit : null,
                    'price'      => $price,
                    'stock'      => $stock,
                ]);

                $pdo->commit();
                $message = "Product \"{$name}\" created.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = 'Please fill in all required fields.';
            $messageType = 'error';
        }
    } elseif ($action === 'run_update') {
        $updateSummary = ProductUpdater::runAll();
        $message = 'AI update finished.';
    }
}

$categories = Category::all();
$suppliers = Supplier::all();
$products = $pdo->query("
    SELECT p.id, p.name, p.category_id, p.supplier_id, p.short_description, p.long_description,
           c.name AS category_name, s.name AS supplier_name,
           p.import_status, COUNT(v.id) AS variant_count
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN product_variants v ON v.product_id = p.id
    GROUP BY p.id
    ORDER BY p.created_at DESC
")->fetchAll();

$variantsByProduct = Variant::forProducts(array_map('intval', array_column($products, 'id')));

$pageTitle = 'Admin — Products';
$activeNav = 'products';
require __DIR__ . '/partials/header.php';
?>

<h1>Products</h1>

<?php if ($message): ?>
    <p class="msg <?= $messageType === 'error' ? 'error' : '' ?>"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<?php if ($updateSummary !== null): ?>
    <div class="panel">
        <h2>AI update results</h2>
        <p>
            processed <?= (int) $updateSummary['processed'] ?> ·
            updated <?= (int) $updateSummary['updated'] ?> ·
            skipped <?= (int) $updateSummary['skipped'] ?>
        </p>
        <?php if (!empty($updateSummary['errors'])): ?>
            <ul class="order-items-list">
                <?php foreach ($updateSummary['errors'] as $error): ?>
                    <li><span style="color: var(--color-danger);"><?= htmlspecialchars($error) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" style="margin-bottom: 20px;">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="run_update">
    <button type="submit" class="btn-primary">✨ Run AI update on imported products</button>
    <p class="field-hint">
        Rewrites description copy and reprices every product with status
        <strong>imported</strong>, then flags it as <strong>update</strong> for review.
    </p>
</form>

<details class="panel" <?= $expandedId === 0 && $message === null ? '' : '' ?>>
    <summary class="panel-summary">Add product</summary>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">

        <label>Category
            <select name="category_id" required>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Supplier (optional)
            <select name="supplier_id">
                <option value="0">— none —</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= (int) $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Name <input type="text" name="name" required></label>
        <label>Short description <input type="text" name="short_description" required></label>
        <label>Long description <textarea name="long_description" rows="4" required></textarea></label>

        <h3>First variant</h3>
        <div class="form-row">
            <label>SKU <input type="text" name="sku" required></label>
            <label>Label <input type="text" name="label" placeholder="6"></label>
        </div>
        <div class="form-row">
            <label>Unit <input type="text" name="unit" placeholder="pack"></label>
            <label>Price <input type="number" name="price" step="0.01" min="0.01" required></label>
        </div>
        <label>Stock <input type="number" name="stock" value="0" min="0" required></label>

        <p class="field-hint">New products start with status <strong>created</strong> — switch them to <strong>approved</strong> to show them on the storefront.</p>

        <button type="submit" class="btn-primary">Create product</button>
    </form>
</details>

<h2>Existing products</h2>
<table class="cart-table admin-table">
    <thead>
        <tr>
            <th></th>
            <th>Name</th>
            <th>Category</th>
            <th>Supplier</th>
            <th>Variants</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($products as $p): ?>
            <?php $pid = (int) $p['id']; $isOpen = $expandedId === $pid; ?>
            <tr class="product-row <?= $isOpen ? 'is-open' : '' ?>" data-product-id="<?= $pid ?>">
                <td>
                    <button type="button" class="row-toggle" data-target="edit-<?= $pid ?>"
                            aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" title="Expand to edit">
                        <span class="chevron">›</span>
                    </button>
                </td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td><?= htmlspecialchars($p['category_name']) ?></td>
                <td><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
                <td>
                    <button type="button" class="variant-count-btn row-toggle" data-target="edit-<?= $pid ?>"
                            title="View variants">
                        <?= (int) $p['variant_count'] ?>
                    </button>
                </td>
                <td>
                    <form method="post" class="inline-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <select name="status" class="status-select" data-status="<?= htmlspecialchars($p['import_status']) ?>"
                                onchange="this.form.submit()" title="Change status">
                            <?php foreach (IMPORT_STATUSES as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $p['import_status'] === $status ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn-secondary">Set</button></noscript>
                    </form>
                </td>
                <td>
                    <div class="table-actions">
                        <button type="button" class="icon-btn magic-btn" data-target="magic-<?= $pid ?>"
                                title="Magic edit with AI">✨</button>
                        <form method="post" class="inline-form"
                              onsubmit="return confirm('Delete &quot;<?= htmlspecialchars(addslashes($p['name'])) ?>&quot;? This cannot be undone.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $pid ?>">
                            <button type="submit" class="icon-btn icon-btn-danger" title="Delete product">🗑</button>
                        </form>
                    </div>
                </td>
            </tr>

            <tr class="edit-row" id="edit-<?= $pid ?>" <?= $isOpen ? '' : 'hidden' ?>>
                <td colspan="7">
                    <div class="edit-block">
                        <form method="post" class="edit-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $pid ?>">

                            <div class="form-row">
                                <label>Name
                                    <input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>" required>
                                </label>
                                <label>Category
                                    <select name="category_id" required>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int) $cat['id'] ?>" <?= (int) $p['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <label>Supplier
                                <select name="supplier_id">
                                    <option value="0">— none —</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?= (int) $sup['id'] ?>" <?= (int) $p['supplier_id'] === (int) $sup['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sup['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>Short description
                                <input type="text" name="short_description" value="<?= htmlspecialchars($p['short_description']) ?>" required>
                            </label>
                            <label>Long description
                                <textarea name="long_description" rows="4" required><?= htmlspecialchars($p['long_description']) ?></textarea>
                            </label>

                            <button type="submit" class="btn-primary">Save changes</button>
                        </form>

                        <div class="variants-panel">
                            <h3>Variants</h3>
                            <?php if (empty($variantsByProduct[$pid])): ?>
                                <p class="field-hint">No variants yet.</p>
                            <?php else: ?>
                                <table class="variants-table">
                                    <thead>
                                        <tr><th>SKU</th><th>Label</th><th>Unit</th><th>Price</th><th>Stock</th><th>Active</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($variantsByProduct[$pid] as $v): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($v['sku']) ?></td>
                                                <td><?= htmlspecialchars((string) ($v['label'] ?? '—')) ?></td>
                                                <td><?= htmlspecialchars((string) ($v['unit'] ?? '—')) ?></td>
                                                <td><?= number_format((float) $v['price'], 2) ?>€</td>
                                                <td><?= (int) $v['stock'] ?></td>
                                                <td>
                                                    <span class="status-badge" data-status="<?= $v['is_active'] ? 'approved' : 'invalid' ?>">
                                                        <?= $v['is_active'] ? 'yes' : 'no' ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>

            <tr class="magic-row" id="magic-<?= $pid ?>" hidden>
                <td colspan="7">
                    <div class="magic-block" data-product-id="<?= $pid ?>">
                        <h3>✨ Magic edit</h3>
                        <p class="field-hint">
                            Describe the change in plain language. The AI returns suggested field values —
                            review them, then apply what you want.
                        </p>
                        <label>Instruction
                            <input type="text" class="magic-prompt"
                                   placeholder="e.g. make the description more concise and mention it's biodegradable">
                        </label>
                        <button type="button" class="btn-secondary magic-run">Generate suggestion</button>
                        <pre class="magic-output" hidden></pre>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script src="/assets/js/admin.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
