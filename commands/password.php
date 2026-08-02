<?php
declare(strict_types=1);
if(count($argv) < 2) {
    echo "Usage: php commands/password.php password\n";
    exit(1);
}
$password = $argv[1];

echo password_hash($password, PASSWORD_BCRYPT), PHP_EOL;
