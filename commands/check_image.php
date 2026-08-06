<?php

declare(strict_types=1);

/**
 * Cleans up a single product's main photo: first tries removing any
 * branding/watermarks/logos via Gemini's image model (the same edit
 * ProductUpdater applies during the bulk "AI update" run). If that fails,
 * falls back to a best-effort web image search (Gemini's Google Search
 * grounding, so no separate search-API key is needed) and downloads any
 * resulting real image to disk for review.
 *
 * By default the web-search result is NOT applied to the live product —
 * it's just downloaded so you can check it's actually the right item.
 * Pass --apply to set it as the product's main image immediately instead.
 *
 * Usage: php commands/check_image.php <product_id> [--apply]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Product;
use Store\Services\GeminiClient;
use Store\Services\ImageDownloader;
use Store\Services\ProductImageManager;
use Store\Services\ProductUpdater;

if (count($argv) < 2 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Usage: php commands/check_image.php <product_id> [--apply]\n");
    exit(1);
}

$productId = (int) $argv[1];
$apply = in_array('--apply', $argv, true);

$product = Product::findForAdmin($productId);
if ($product === null) {
    fwrite(STDERR, "No product with id {$productId}.\n");
    exit(1);
}

$imagePath = trim((string) ($product['image_path'] ?? ''));
if ($imagePath === '') {
    fwrite(STDERR, "Product #{$productId} ({$product['name']}) has no image.\n");
    exit(1);
}

echo "Product #{$productId} — {$product['name']}\n";
echo "Current image: {$imagePath}\n\n";

$fullPath = __DIR__ . '/../public/' . $imagePath;

if (!is_file($fullPath)) {
    // removeTextFromImage() no-ops silently on a missing file (fine for its
    // original bulk-run caller, which just skips to the next image) — here
    // that would misreport as success, so treat it as its own failure case.
    echo "Local image file not found on disk ({$imagePath}) — nothing to de-brand.\n\n";
} else {
    echo "Removing branding via Gemini...\n";

    try {
        ProductUpdater::removeTextFromImage($imagePath);
        echo "Done — branding removed, {$imagePath} updated in place.\n";
        exit(0);
    } catch (Throwable $e) {
        echo "Branding removal failed: {$e->getMessage()}\n\n";
    }
}

echo "Falling back to a web image search...\n";

try {
    $url = (new GeminiClient())->findImageUrl($product['name']);
} catch (Throwable $e) {
    fwrite(STDERR, "Web search failed: {$e->getMessage()}\n");
    exit(1);
}

if ($url === null) {
    fwrite(STDERR, "Web search did not turn up a usable image URL.\n");
    exit(1);
}

echo "Candidate image found: {$url}\n";

$downloaded = ImageDownloader::download($url, $productId);
if ($downloaded === null) {
    fwrite(STDERR, "Could not download the candidate image (unreachable or not a real image).\n");
    exit(1);
}

if ($apply) {
    ProductImageManager::attachExisting($productId, $downloaded);
    echo "Saved and applied as the product's main image: public/{$downloaded}\n";
} else {
    echo "Saved for review (NOT applied to the product): public/{$downloaded}\n";
    echo "If it's actually the right product, re-run with --apply:\n";
    echo "  php commands/check_image.php {$productId} --apply\n";
}
