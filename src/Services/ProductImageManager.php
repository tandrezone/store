<?php

declare(strict_types=1);

namespace Store\Services;

use PDO;
use RuntimeException;
use Store\Config\Database;

/**
 * Admin-driven counterpart to ImageDownloader: instead of pulling an image
 * from a supplier URL, this saves a file the admin uploaded directly, and
 * lets them delete or re-order a product's existing images. Shares the
 * same public/assets/images/products storage and images/image_path
 * column conventions as the importer so both write paths stay compatible.
 */
class ProductImageManager
{
    private const PUBLIC_SUBDIR = 'assets/images/products';
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Validates a single $_FILES entry and, if it passes, saves it and
     * appends it to $productId's images (setting image_path too if this
     * is the first image). Returns the new relative path. Throws a
     * user-facing message on any validation failure — callers should show
     * that message rather than a generic "upload failed".
     */
    public static function addUpload(int $productId, array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('No file was uploaded.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Image is larger than ' . (self::MAX_BYTES / 1024 / 1024) . 'MB.');
        }

        $info = @getimagesize($tmpPath);
        $mime = $info['mime'] ?? null;
        $ext = self::ALLOWED_MIME[$mime] ?? null;
        if ($ext === null) {
            throw new RuntimeException('Only JPEG, PNG, WebP, and GIF images are allowed.');
        }

        $dir = self::localDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = $productId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        $relativePath = self::PUBLIC_SUBDIR . '/' . $filename;

        $pdo = Database::connection();
        $paths = self::currentPaths($pdo, $productId);
        $paths[] = $relativePath;
        self::save($pdo, $productId, array_values(array_unique($paths)));

        return $relativePath;
    }

    /**
     * Removes $relativePath from $productId's images and deletes the file,
     * promoting the next remaining image to image_path if the removed one
     * was the main. No-ops if $relativePath isn't actually one of this
     * product's stored images — guards against deleting an unrelated file
     * via a crafted path, since we only ever unlink paths already present
     * in the DB record rather than trusting the input directly.
     */
    public static function remove(int $productId, string $relativePath): void
    {
        $pdo = Database::connection();
        $paths = self::currentPaths($pdo, $productId);

        if (!in_array($relativePath, $paths, true)) {
            return;
        }

        $remaining = array_values(array_filter($paths, static fn ($path) => $path !== $relativePath));
        self::save($pdo, $productId, $remaining);

        $fullPath = self::localDir() . '/' . basename($relativePath);
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Reorders $productId's images so $relativePath comes first (the main
     * photo shown on listings). No-ops if it isn't one of this product's
     * stored images, for the same reason as remove() above.
     */
    public static function setMain(int $productId, string $relativePath): void
    {
        $pdo = Database::connection();
        $paths = self::currentPaths($pdo, $productId);

        if (!in_array($relativePath, $paths, true)) {
            return;
        }

        $reordered = array_values(array_unique(array_merge([$relativePath], $paths)));
        self::save($pdo, $productId, $reordered);
    }

    /**
     * Attaches a file that's already on disk under the products directory
     * (e.g. saved by ImageDownloader) to $productId's image list, without
     * going through the $_FILES upload flow addUpload() expects. Used by
     * commands/check_image.php once a downloaded web-search candidate has
     * been reviewed. No-ops if the file isn't actually present.
     */
    public static function attachExisting(int $productId, string $relativePath, bool $asMain = true): void
    {
        if (!is_file(self::localDir() . '/' . basename($relativePath))) {
            return;
        }

        $pdo = Database::connection();
        $paths = self::currentPaths($pdo, $productId);
        $merged = $asMain ? array_merge([$relativePath], $paths) : array_merge($paths, [$relativePath]);

        self::save($pdo, $productId, array_values(array_unique($merged)));
    }

    private static function currentPaths(PDO $pdo, int $productId): array
    {
        $stmt = $pdo->prepare('SELECT images FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $decoded = json_decode((string) $stmt->fetchColumn(), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private static function save(PDO $pdo, int $productId, array $paths): void
    {
        $pdo->prepare('UPDATE products SET images = :images, image_path = :image_path WHERE id = :id')
            ->execute([
                'images'     => !empty($paths) ? json_encode($paths) : null,
                'image_path' => $paths[0] ?? null,
                'id'         => $productId,
            ]);
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed.',
        };
    }

    private static function localDir(): string
    {
        return __DIR__ . '/../../public/' . self::PUBLIC_SUBDIR;
    }
}
