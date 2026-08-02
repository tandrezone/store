<?php

declare(strict_types=1);

namespace Store\Config;

/**
 * Single admin account for the /admin area.
 * Override ADMIN_USERNAME / ADMIN_PASSWORD_HASH via real environment
 * variables in production. Generate a new hash with:
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
        return getenv('ADMIN_PASSWORD_HASH')
            ?: '$2y$12$Vy0UtiaIc8NnenEC/Z788OuuDEZCfIXlIZ0DtKawOxs1DRG7ZDEuW';
    }
}
