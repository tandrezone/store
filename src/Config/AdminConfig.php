<?php

declare(strict_types=1);

namespace Store\Config;

use RuntimeException;

/**
 * Single admin account for the /admin area.
 * ADMIN_USERNAME / ADMIN_PASSWORD_HASH must be set via environment
 * variables (see .env.example) — there is no built-in default account.
 * Generate a new hash with:
 *   php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"
 */
class AdminConfig
{
    public static function username(): string
    {
        return getenv('ADMIN_USERNAME') ?: 'admin';
    }

    public static function passwordHash(): string
    {
        $hash = getenv('ADMIN_PASSWORD_HASH');
        if ($hash === false || $hash === '') {
            throw new RuntimeException('ADMIN_PASSWORD_HASH is not set.');
        }

        return $hash;
    }
}
