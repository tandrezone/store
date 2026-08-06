<?php

declare(strict_types=1);

namespace Store\Services;

use Throwable;

/**
 * Downloads supplier-provided product images to local disk so the
 * storefront never hotlinks a third-party URL. Same SSRF protections as
 * ProductImporter's feed fetch: UrlGuard resolves + validates the host,
 * then curl is pinned to that exact IP.
 */
class ImageDownloader
{
    private const PUBLIC_SUBDIR = 'assets/images/products';
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Downloads $url and returns its path relative to /public (e.g.
     * "assets/images/products/12-ab12cd34ef56.jpg"), or null if the URL is
     * empty/unreachable/not an allowed image type. Idempotent — re-running
     * with the same URL for the same product returns the cached file
     * instead of re-downloading it.
     */
    public static function download(string $url, int $productId): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $filenameBase = $productId . '-' . substr(md5($url), 0, 12);
        $dir = self::localDir();

        foreach (self::ALLOWED_MIME as $ext) {
            $existing = $dir . '/' . $filenameBase . '.' . $ext;
            if (is_file($existing)) {
                return self::PUBLIC_SUBDIR . '/' . basename($existing);
            }
        }

        try {
            $target = UrlGuard::resolvePublicHttpUrl($url);
        } catch (Throwable $e) {
            return null;
        }

        $data = self::fetch($url, $target);
        if ($data === null) {
            return null;
        }

        $info = @getimagesizefromstring($data);
        $mime = $info['mime'] ?? null;
        $ext = self::ALLOWED_MIME[$mime] ?? null;
        if ($ext === null) {
            return null;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $destination = $dir . '/' . $filenameBase . '.' . $ext;

        // Write to a temp file and rename into place — rename() is atomic,
        // so a concurrent request never sees a half-written file.
        $tmpPath = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmpPath, $data);
        rename($tmpPath, $destination);

        return self::PUBLIC_SUBDIR . '/' . basename($destination);
    }

    /**
     * Streams the response through a size-capped write callback so an
     * oversized or malicious response is aborted mid-transfer rather than
     * fully buffered into memory first.
     */
    private static function fetch(string $url, array $target): ?string
    {
        $buffer = '';
        $exceeded = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$exceeded) {
                $buffer .= $chunk;
                if (strlen($buffer) > self::MAX_BYTES) {
                    $exceeded = true;
                    return -1;
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($exceeded || $error !== '' || $httpCode >= 400 || $buffer === '') {
            return null;
        }

        return $buffer;
    }

    private static function localDir(): string
    {
        return __DIR__ . '/../../public/' . self::PUBLIC_SUBDIR;
    }
}
