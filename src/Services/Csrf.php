<?php

declare(strict_types=1);

namespace Store\Services;

/**
 * Session-bound CSRF token, shared by every state-changing form in the
 * admin area (and available to the storefront checkout).
 */
class Csrf
{
    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /** Returns the current token, generating one on first use. */
    public static function token(): string
    {
        self::ensureSession();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function check(?string $submittedToken): bool
    {
        self::ensureSession();

        return is_string($submittedToken)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $submittedToken);
    }

    /**
     * Verifies the token from $_POST and sends a 400 + exits if it's missing
     * or wrong. Call this before acting on any admin POST request.
     */
    public static function requireValid(): void
    {
        if (!self::check($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo 'Invalid or expired form submission. Please go back and try again.';
            exit;
        }
    }
}
