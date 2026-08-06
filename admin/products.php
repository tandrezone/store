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
use Store\Services\HtmlSanitizer;
use Store\Services\ProductImageManager;
use Store\Services\ProductUpdater;

AdminAuth::requireAuth();

const IMPORT_STATUSES = ['created', 'imported', 'invalid', 'update', 'approved'];

/**
 * Renders a contenteditable rich-text editor backed by a hidden
 * textarea[name=long_description] that admin.js keeps in sync on every
 * edit — the server side only ever sees plain textarea POST data, so
 * Product::update()/the create handler don't need to change. $html must
 * already be sanitized (HtmlSanitizer::clean()) — it's written directly
 * into the editor's markup so formatting round-trips correctly.
 */
function wysiwyg_field(string $html): string
{
    ob_start();
    ?>
    <div class="wysiwyg" data-wysiwyg>
        <div class="wysiwyg-toolbar" role="toolbar" aria-label="Formatting">
            <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
            <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
            <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
            <button type="button" data-cmd="insertUnorderedList" title="Bullet list">&bull; List</button>
            <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
            <button type="button" data-cmd="createLink" title="Insert link">&#128279;</button>
            <button type="button" data-cmd="removeFormat" title="Clear formatting">&times;</button>
        </div>
        <div class="wysiwyg-editor" contenteditable="true"><?= $html ?></div>
        <textarea name="long_description" hidden><?= htmlspecialchars($html) ?></textarea>
    </div>
    <?php
    return ob_get_clean();
}

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
    } elseif ($action === 'bulk_delete') {
        $ids = array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn ($v) => $v > 0));

        if (empty($ids)) {
            $message = 'No products selected.';
            $messageType = 'error';
        } else {
            $deleted = 0;
            $failed = 0;
            foreach ($ids as $bulkId) {
                try {
                    Product::delete($bulkId);
                    $deleted++;
                } catch (Throwable $e) {
                    $failed++;
                }
            }
            $message = "Deleted {$deleted} product(s).";
            if ($failed > 0) {
                $message .= " {$failed} could not be deleted (referenced by existing orders).";
                $messageType = $deleted > 0 ? 'success' : 'error';
            }
        }
    } elseif ($action === 'bulk_set_status') {
        $ids = array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn ($v) => $v > 0));
        $status = (string) ($_POST['status'] ?? '');

        if (empty($ids) || !in_array($status, IMPORT_STATUSES, true)) {
            $message = 'No products selected or invalid status.';
            $messageType = 'error';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE products SET import_status = ? WHERE id IN ({$placeholders})");
            $stmt->execute([$status, ...array_values($ids)]);
            $message = 'Updated status for ' . $stmt->rowCount() . ' product(s).';
        }
    } elseif ($action === 'update' && $id > 0) {
        Product::update($id, [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'category_id' => (int) ($_POST['category_id'] ?? 0),
            'supplier_id' => ((int) ($_POST['supplier_id'] ?? 0)) ?: null,
            'short_description' => trim((string) ($_POST['short_description'] ?? '')),
            'long_description' => HtmlSanitizer::clean((string) ($_POST['long_description'] ?? '')),
        ]);
        $message = 'Product updated.';
        $expandedId = $id;
    } elseif ($action === 'upload_images' && $id > 0) {
        $files = $_FILES['images']['name'] ?? [];
        $uploaded = 0;
        $errors = [];

        foreach (array_keys((array) $files) as $i) {
            if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $file = [
                'name'     => $_FILES['images']['name'][$i],
                'type'     => $_FILES['images']['type'][$i],
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error'    => $_FILES['images']['error'][$i],
                'size'     => $_FILES['images']['size'][$i],
            ];

            try {
                ProductImageManager::addUpload($id, $file);
                $uploaded++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $message = $uploaded > 0 ? "Uploaded {$uploaded} image(s)." : 'No images were uploaded.';
        if (!empty($errors)) {
            $message .= ' ' . implode(' ', $errors);
            $messageType = $uploaded > 0 ? 'success' : 'error';
        }
        $expandedId = $id;
    } elseif ($action === 'delete_image' && $id > 0) {
        ProductImageManager::remove($id, (string) ($_POST['path'] ?? ''));
        $message = 'Image removed.';
        $expandedId = $id;
    } elseif ($action === 'set_main_image' && $id > 0) {
        ProductImageManager::setMain($id, (string) ($_POST['path'] ?? ''));
        $message = 'Main image updated.';
        $expandedId = $id;
    } elseif ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $shortDesc = trim((string) ($_POST['short_description'] ?? ''));
        $longDesc = HtmlSanitizer::clean((string) ($_POST['long_description'] ?? ''));
        $longDescHasText = trim(strip_tags($longDesc)) !== '';
        $label = trim((string) ($_POST['label'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $stock = (int) ($_POST['stock'] ?? 0);
        $sku = trim((string) ($_POST['sku'] ?? ''));

        if ($name && $categoryId && $shortDesc && $longDescHasText && $sku && $price > 0) {
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

const SORT_COLUMNS = [
    'name' => 'p.name',
    'category' => 'c.name',
    'supplier' => 's.name',
    'variants' => 'variant_count',
    // import_status is a MySQL ENUM, so ordering by the column directly
    // sorts by declaration order, not alphabetically — CAST to CHAR so
    // the ▲/▼ arrows match what they visually promise.
    'status' => 'CAST(p.import_status AS CHAR)',
];

/**
 * Renders a sortable column header link, toggling direction on repeat
 * clicks and preserving the active status filter.
 */
function sort_link(string $column, string $label, string $sort, string $dir, string $statusFilter): string
{
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    $query = ['sort' => $column, 'dir' => $nextDir];
    if ($statusFilter !== '') {
        $query['status'] = $statusFilter;
    }

    $arrow = '';
    if ($sort === $column) {
        $arrow = $dir === 'asc' ? ' ▲' : ' ▼';
    }

    $href = '/admin/products.php?' . http_build_query($query);

    return '<a href="' . htmlspecialchars($href) . '" class="sort-link">' . htmlspecialchars($label) . $arrow . '</a>';
}

/** Renders a pagination link, preserving the active sort/filter. */
function page_url(int $page, string $sort, string $dir, string $statusFilter): string
{
    $query = ['page' => $page];
    if ($statusFilter !== '') {
        $query['status'] = $statusFilter;
    }
    if ($sort !== '') {
        $query['sort'] = $sort;
        $query['dir'] = $dir;
    }

    return '/admin/products.php?' . http_build_query($query);
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($statusFilter, IMPORT_STATUSES, true)) {
    $statusFilter = '';
}

$sort = (string) ($_GET['sort'] ?? '');
if (!array_key_exists($sort, SORT_COLUMNS)) {
    $sort = '';
}
$dir = strtolower((string) ($_GET['dir'] ?? '')) === 'desc' ? 'desc' : 'asc';

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$categories = Category::all();
$suppliers = Supplier::all();

$productsParams = [];
$countSql = 'SELECT COUNT(*) FROM products p';
if ($statusFilter !== '') {
    $countSql .= ' WHERE p.import_status = :status';
    $productsParams['status'] = $statusFilter;
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($productsParams);
$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalProducts / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$productsSql = "
    SELECT p.id, p.name, p.category_id, p.supplier_id, p.short_description, p.long_description,
           p.image_path, p.images,
           c.name AS category_name, s.name AS supplier_name,
           p.import_status, COUNT(v.id) AS variant_count
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN product_variants v ON v.product_id = p.id
";
if ($statusFilter !== '') {
    $productsSql .= ' WHERE p.import_status = :status';
}
$productsSql .= ' GROUP BY p.id';
$productsSql .= $sort !== ''
    ? " ORDER BY " . SORT_COLUMNS[$sort] . " {$dir}"
    : ' ORDER BY p.created_at DESC';
$productsSql .= " LIMIT {$perPage} OFFSET {$offset}";

$productsStmt = $pdo->prepare($productsSql);
$productsStmt->execute($productsParams);
$products = $productsStmt->fetchAll();

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
        <label>Long description</label>
        <?= wysiwyg_field('') ?>

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

<div class="filters">
    <a href="/admin/products.php" class="filter-pill <?= $statusFilter === '' ? 'active' : '' ?>">All</a>
    <?php foreach (IMPORT_STATUSES as $status): ?>
        <a href="/admin/products.php?status=<?= htmlspecialchars($status) ?>"
           class="filter-pill <?= $statusFilter === $status ? 'active' : '' ?>">
            <?= htmlspecialchars(ucfirst($status)) ?>
        </a>
    <?php endforeach; ?>
</div>

<form id="bulk-form" method="post" class="bulk-actions">
    <?= Csrf::field() ?>
    <span class="bulk-count" data-bulk-count>0 selected</span>
    <button type="submit" name="action" value="bulk_delete" class="btn-danger" title="Delete selected">
        🗑 Delete selected
    </button>
    <select name="status" class="status-select" required>
        <option value="" disabled selected>— choose status —</option>
        <?php foreach (IMPORT_STATUSES as $status): ?>
            <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" name="action" value="bulk_set_status" class="btn-secondary">Set status</button>
</form>

<table class="cart-table admin-table">
    <thead>
        <tr>
            <th><input type="checkbox" id="select-all-products" title="Select all"></th>
            <th></th>
            <th><?= sort_link('name', 'Name', $sort, $dir, $statusFilter) ?></th>
            <th><?= sort_link('category', 'Category', $sort, $dir, $statusFilter) ?></th>
            <th><?= sort_link('supplier', 'Supplier', $sort, $dir, $statusFilter) ?></th>
            <th><?= sort_link('variants', 'Variants', $sort, $dir, $statusFilter) ?></th>
            <th><?= sort_link('status', 'Status', $sort, $dir, $statusFilter) ?></th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($products as $p): ?>
            <?php $pid = (int) $p['id']; $isOpen = $expandedId === $pid; ?>
            <tr class="product-row <?= $isOpen ? 'is-open' : '' ?>" data-product-id="<?= $pid ?>">
                <td>
                    <input type="checkbox" form="bulk-form" name="ids[]" value="<?= $pid ?>" class="product-select">
                </td>
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
                <td colspan="8">
                    <div class="edit-block">
                        <div class="images-panel">
                            <h3>Images</h3>
                            <?php
                                $images = json_decode((string) ($p['images'] ?? ''), true);
                                $images = is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
                                if (empty($images) && !empty($p['image_path'])) {
                                    $images = [$p['image_path']];
                                }
                            ?>
                            <?php if (empty($images)): ?>
                                <p class="field-hint">No images yet — upload one below.</p>
                            <?php else: ?>
                                <div class="image-grid">
                                    <?php foreach ($images as $i => $img): ?>
                                        <div class="image-thumb <?= $i === 0 ? 'is-main' : '' ?>">
                                            <img src="/<?= htmlspecialchars($img) ?>" alt="">
                                            <?php if ($i === 0): ?><span class="main-badge">main</span><?php endif; ?>
                                            <div class="image-thumb-actions">
                                                <?php if ($i !== 0): ?>
                                                    <form method="post" class="inline-form">
                                                        <?= Csrf::field() ?>
                                                        <input type="hidden" name="action" value="set_main_image">
                                                        <input type="hidden" name="id" value="<?= $pid ?>">
                                                        <input type="hidden" name="path" value="<?= htmlspecialchars($img) ?>">
                                                        <button type="submit" class="icon-btn" title="Set as main image">&#9733;</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" class="inline-form"
                                                      onsubmit="return confirm('Remove this image?');">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="action" value="delete_image">
                                                    <input type="hidden" name="id" value="<?= $pid ?>">
                                                    <input type="hidden" name="path" value="<?= htmlspecialchars($img) ?>">
                                                    <button type="submit" class="icon-btn icon-btn-danger" title="Remove image">&#128465;</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" enctype="multipart/form-data" class="upload-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="upload_images">
                                <input type="hidden" name="id" value="<?= $pid ?>">
                                <label>Add images
                                    <input type="file" name="images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple required>
                                </label>
                                <button type="submit" class="btn-secondary">Upload</button>
                            </form>
                        </div>

                        <div class="edit-block-grid">
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
                                <label>Long description</label>
                                <?= wysiwyg_field(HtmlSanitizer::clean((string) $p['long_description'])) ?>

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
                    </div>
                </td>
            </tr>

            <tr class="magic-row" id="magic-<?= $pid ?>" hidden>
                <td colspan="8">
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

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <a href="<?= htmlspecialchars(page_url(max(1, $page - 1), $sort, $dir, $statusFilter)) ?>"
           class="btn-secondary <?= $page <= 1 ? 'is-disabled' : '' ?>">‹ Prev</a>
        <span class="bulk-count">Page <?= $page ?> of <?= $totalPages ?></span>
        <a href="<?= htmlspecialchars(page_url(min($totalPages, $page + 1), $sort, $dir, $statusFilter)) ?>"
           class="btn-secondary <?= $page >= $totalPages ? 'is-disabled' : '' ?>">Next ›</a>
    </div>
<?php endif; ?>

<script src="/assets/js/admin.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
