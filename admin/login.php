<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Services\AdminAuth;

if (AdminAuth::check()) {
    header('Location: /admin/products.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (AdminAuth::attempt($username, $password)) {
        header('Location: /admin/products.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Login</title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
    .login-page { display: flex; align-items: center; justify-content: center; min-height: 80vh; }
    .login-card { width: 100%; max-width: 360px; }
</style>
</head>
<body>
<main class="container login-page">
    <div class="panel login-card">
        <span class="hero-eyebrow">Restricted area</span>
        <h1 style="margin: 10px 0 20px; font-size: 1.5rem;">Admin login</h1>

        <?php if ($error): ?><p class="msg error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

        <form method="post">
            <label>Username <input type="text" name="username" required autofocus></label>
            <label>Password <input type="password" name="password" required></label>
            <button type="submit" class="btn-primary">Log in</button>
        </form>
    </div>
</main>
</body>
</html>
