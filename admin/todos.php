<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Todo;
use Store\Services\AdminAuth;
use Store\Services\Csrf;

AdminAuth::requireAuth();

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $category = (string) ($_POST['category'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (!in_array($category, Todo::CATEGORIES, true) || $title === '') {
            $message = 'Please provide a title and a valid category.';
        } else {
            Todo::create($category, $title, $description);
            $message = 'Todo added.';
        }
    } elseif ($action === 'toggle') {
        Todo::toggleDone((int) ($_POST['id'] ?? 0));
    } elseif ($action === 'delete') {
        Todo::delete((int) ($_POST['id'] ?? 0));
        $message = 'Todo removed.';
    }
}

$grouped = Todo::allByCategory();

$columns = [
    'bug' => 'Bugs',
    'nice_to_have' => 'Nice to have',
    'feature' => 'New features',
];

$pageTitle = 'Admin — Todo';
$activeNav = 'todos';
require __DIR__ . '/partials/header.php';
?>

<h1>Todo</h1>

<?php if ($message): ?><p class="msg"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="panel">
    <h2>Add item</h2>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <label>Category
            <select name="category" required>
                <?php foreach ($columns as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Title <input type="text" name="title" required></label>
        <label>Description <textarea name="description" rows="2"></textarea></label>
        <button type="submit" class="btn-primary">Add</button>
    </form>
</div>

<div class="grid-3">
    <?php foreach ($columns as $category => $label): ?>
        <div class="panel">
            <h2><?= htmlspecialchars($label) ?> (<?= count($grouped[$category]) ?>)</h2>
            <ul class="todo-list">
                <?php foreach ($grouped[$category] as $item): ?>
                    <li class="<?= $item['is_done'] ? 'is-done' : '' ?>">
                        <div>
                            <div class="todo-title"><?= htmlspecialchars($item['title']) ?></div>
                            <?php if ($item['description'] !== null && $item['description'] !== ''): ?>
                                <div class="todo-desc"><?= htmlspecialchars($item['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="todo-actions">
                            <form method="post" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="btn-primary"><?= $item['is_done'] ? 'Reopen' : 'Done' ?></button>
                            </form>
                            <form method="post" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($grouped[$category])): ?>
                    <li>Nothing here yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
