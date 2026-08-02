<?php

declare(strict_types=1);

namespace Store\Services;

use Store\Config\AdminConfig;

/**
 * Session-based login for the /admin area, backed by a single account
 * configured in Store\Config\AdminConfig.
 */
class AdminAuth
{
    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function check(): bool
    {
        self::ensureSession();
        return !empty($_SESSION['admin_authenticated']);
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /admin/login.php');
            exit;
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        self::ensureSession();

        $usernameMatches = hash_equals(AdminConfig::username(), $username);
        $passwordMatches = password_verify($password, AdminConfig::passwordHash());

        if (!$usernameMatches || !$passwordMatches) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;

        return true;
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        session_destroy();
    }
}
