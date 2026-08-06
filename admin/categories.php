<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Category;
use Store\Services\AdminAuth;
use Store\Services\Csrf;

AdminAuth::requireAuth();

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $message = 'Please provide a name.';
            $messageType = 'error';
        } else {
            Category::create($name);
            $message = "Category \"{$name}\" added.";
        }
    } elseif ($action === 'update' && $id > 0) {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $message = 'Please provide a name.';
            $messageType = 'error';
        } else {
            Category::update($id, $name);
            $message = 'Category updated.';
        }
    } elseif ($action === 'delete' && $id > 0) {
        try {
            Category::delete($id);
            $message = 'Category deleted.';
        } catch (Throwable $e) {
            $message = 'Could not delete: it still has products assigned to it.';
            $messageType = 'error';
        }
    }
}

$categories = Category::allWithProductCounts();

$pageTitle = 'Admin — Categories';
$activeNav = 'categories';
require __DIR__ . '/partials/header.php';
?>

<h1>Categories</h1>

<?php if ($message): ?>
    <p class="msg <?= $messageType === 'error' ? 'error' : '' ?>"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<div class="panel">
    <h2>Add category</h2>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <label>Name <input type="text" name="name" required></label>
        <button type="submit" class="btn-primary">Add category</button>
    </form>
</div>

<h2>Existing categories</h2>
<table class="cart-table admin-table">
    <thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Actions</th></tr></thead>
    <tbody>
        <?php if (empty($categories)): ?>
            <tr><td colspan="4" style="color: var(--color-muted);">No categories yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($categories as $cat): ?>
            <?php $cid = (int) $cat['id']; ?>
            <tr>
                <td>
                    <form method="post" class="inline-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($cat['name']) ?>" class="category-name-input" required>
                        <button type="submit" class="btn-secondary">Save</button>
                    </form>
                </td>
                <td><span class="field-hint"><?= htmlspecialchars($cat['slug']) ?></span></td>
                <td><?= (int) $cat['product_count'] ?></td>
                <td>
                    <form method="post" class="inline-form"
                          onsubmit="return confirm('Delete &quot;<?= htmlspecialchars(addslashes($cat['name'])) ?>&quot;? This cannot be undone.');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <button type="submit" class="icon-btn icon-btn-danger" title="Delete category">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/partials/footer.php'; ?>
