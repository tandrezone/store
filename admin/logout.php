<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Services\AdminAuth;

AdminAuth::logout();
header('Location: /admin/login.php');
exit;
