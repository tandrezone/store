<?php

declare(strict_types=1);

namespace Store\Services;

use Store\Config\AdminConfig;
use Store\Config\Database;

/**
 * Session-based login for the /admin area, backed by a single account
 * configured in Store\Config\AdminConfig.
 */
class AdminAuth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_WINDOW_MINUTES = 15;

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

    /**
     * Returns true and logs the caller in on success. Returns false on bad
     * credentials or when this IP has made too many failed attempts
     * recently — callers can't tell the two apart, which is intentional
     * (no hint to an attacker about which one it is).
     */
    public static function attempt(string $username, string $password): bool
    {
        self::ensureSession();

        $ip = self::clientIp();
        if (self::isLockedOut($ip)) {
            return false;
        }

        $usernameMatches = hash_equals(AdminConfig::username(), $username);
        $passwordMatches = password_verify($password, AdminConfig::passwordHash());

        if (!$usernameMatches || !$passwordMatches) {
            self::recordFailedAttempt($ip);
            return false;
        }

        self::clearFailedAttempts($ip);
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

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    private static function isLockedOut(string $ip): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND created_at > (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->execute(['ip' => $ip, 'minutes' => self::LOCKOUT_WINDOW_MINUTES]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    private static function recordFailedAttempt(string $ip): void
    {
        Database::connection()
            ->prepare('INSERT INTO login_attempts (ip_address) VALUES (:ip)')
            ->execute(['ip' => $ip]);
    }

    private static function clearFailedAttempts(string $ip): void
    {
        Database::connection()
            ->prepare('DELETE FROM login_attempts WHERE ip_address = :ip')
            ->execute(['ip' => $ip]);
    }
}
