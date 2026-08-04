<?php

declare(strict_types=1);

namespace Store\Services;

/**
 * Generates and caches a placeholder product image (cave/lab background
 * with the product name centered in the dark panel) for products that
 * don't have a real photo yet.
 */
class DefaultProductImage
{
    private const BG_PATH = __DIR__ . '/../Assets/default-image/card_bg.png';
    private const FONT_PATH = __DIR__ . '/../Assets/default-image/Roboto-Regular.ttf';
    private const PUBLIC_SUBDIR = 'assets/images/generated';

    /**
     * Returns the image_path (relative to /public) for the given product,
     * generating and caching the file on first use. Only call this for a
     * single product per request (e.g. the product detail page) — for a
     * listing of many products use cachedPathFor() instead, since
     * generating on the spot for every miss would make the listing page's
     * response time scale with how many products still need a placeholder.
     */
    public static function pathFor(array $product): string
    {
        $fullPath = self::fullPathFor($product);

        if (!is_file($fullPath)) {
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            self::render(self::nameFor($product), $fullPath);
        }

        return self::PUBLIC_SUBDIR . '/' . basename($fullPath);
    }

    /**
     * Non-generating lookup for listing pages: returns the cached
     * placeholder path if it already exists on disk, or '' if it still
     * needs to be (re)generated — run commands/regenerate-images.php to
     * fill those in ahead of time rather than on a live request.
     */
    public static function cachedPathFor(array $product): string
    {
        $fullPath = self::fullPathFor($product);

        return is_file($fullPath) ? self::PUBLIC_SUBDIR . '/' . basename($fullPath) : '';
    }

    /** Deletes the cached placeholder for this product, if one exists. */
    public static function forget(array $product): void
    {
        $fullPath = self::fullPathFor($product);
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    private static function nameFor(array $product): string
    {
        return trim((string) ($product['name'] ?? '')) ?: 'Product';
    }

    /**
     * The cache filename is derived from the product's current name, so a
     * rename naturally invalidates it — the next pathFor() call redraws a
     * fresh image instead of showing stale text.
     */
    private static function fullPathFor(array $product): string
    {
        $name = self::nameFor($product);
        $id = (int) ($product['id'] ?? 0);
        $filename = $id . '-' . substr(md5($name), 0, 8) . '.jpg';
        $dir = __DIR__ . '/../../public/' . self::PUBLIC_SUBDIR;

        return $dir . '/' . $filename;
    }

    private static function render(string $name, string $destinationPath): void
    {
        $info = getimagesize(self::BG_PATH);
        $width = $info[0] ?? 1024;
        $height = $info[1] ?? 1024;

        $image = match ($info['mime'] ?? '') {
            'image/png' => imagecreatefrompng(self::BG_PATH),
            'image/webp' => imagecreatefromwebp(self::BG_PATH),
            default => imagecreatefromjpeg(self::BG_PATH),
        };
        imageantialias($image, true);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        self::drawCenteredText($image, $name, $width, $height);

        // Render to a temp file and rename into place — rename() is atomic,
        // so a concurrent request that hits the same cache miss will either
        // see no file yet or the finished one, never a half-written JPEG.
        $tmpPath = $destinationPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        imagejpeg($image, $tmpPath, 90);
        imagedestroy($image);
        rename($tmpPath, $destinationPath);
    }

    private static function drawCenteredText($image, string $text, int $width, int $height): void
    {
        // Panel coordinates match the dark square baked into card_bg.png.
        $panelLeft = (int) ($width * 0.27);
        $panelTop = (int) ($height * 0.41);
        $panelRight = (int) ($width * 0.78);
        $panelBottom = (int) ($height * 0.77);
        $maxTextWidth = (int) (($panelRight - $panelLeft) * 0.9);

        $normalized = trim(preg_replace('/\s+/', ' ', $text));
        $fontSize = 40;
        if (strlen($normalized) > 24) {
            $fontSize = 34;
        }
        if (strlen($normalized) > 34) {
            $fontSize = 28;
        }

        $lines = self::wrapText($normalized, $fontSize, $maxTextWidth);
        $lineHeight = (int) ($fontSize * 1.4);
        $blockHeight = count($lines) * $lineHeight;
        $panelCenterY = (int) (($panelTop + $panelBottom) / 2);
        $startY = $panelCenterY - (int) ($blockHeight / 2) + $fontSize;

        $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, 40);
        $textColor = imagecolorallocatealpha($image, 235, 250, 248, 0);
        $panelCenterX = (int) (($panelLeft + $panelRight) / 2);

        foreach ($lines as $index => $line) {
            $box = imagettfbbox($fontSize, 0, self::FONT_PATH, $line);
            $lineWidth = abs($box[2] - $box[0]);
            $x = $panelCenterX - (int) ($lineWidth / 2);
            $y = $startY + ($index * $lineHeight);

            imagettftext($image, $fontSize, 0, $x + 1, $y + 2, $shadowColor, self::FONT_PATH, $line);
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, self::FONT_PATH, $line);
        }
    }

    private static function wrapText(string $text, int $fontSize, int $maxWidth): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $candidate = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $box = imagettfbbox($fontSize, 0, self::FONT_PATH, $candidate);
            $candidateWidth = abs($box[2] - $box[0]);

            if ($candidateWidth <= $maxWidth || $currentLine === '') {
                $currentLine = $candidate;
                continue;
            }

            $lines[] = $currentLine;
            $currentLine = $word;
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return array_slice($lines, 0, 4);
    }
}
