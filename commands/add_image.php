<?php

declare(strict_types=1);

/**
 * Downloads an image from a URL and attaches it to a product: saves the
 * file under public/assets/images/products (through the same SSRF-guarded,
 * mime-checked fetch ImageDownloader already uses for supplier feeds) and
 * records it on the product — as the main image by default, or appended
 * to the gallery without disturbing the current main image if --gallery
 * is passed.
 *
 * Usage: php commands/add_image.php <product_id> <image_url> [--gallery]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Models\Product;
use Store\Services\ImageDownloader;
use Store\Services\ProductImageManager;

if (count($argv) < 3 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Usage: php commands/add_image.php <product_id> <image_url> [--gallery]\n");
    exit(1);
}

$productId = (int) $argv[1];
$url = trim($argv[2]);
$asMain = !in_array('--gallery', $argv, true);

$product = Product::findForAdmin($productId);
if ($product === null) {
    fwrite(STDERR, "No product with id {$productId}.\n");
    exit(1);
}

echo "Product #{$productId} — {$product['name']}\n";
echo "Downloading {$url}...\n";

$relativePath = ImageDownloader::download($url, $productId);
if ($relativePath === null) {
    fwrite(STDERR, "Could not download that URL (unreachable, blocked, or not a real image).\n");
    exit(1);
}

ProductImageManager::attachExisting($productId, $relativePath, $asMain);

echo "Saved: public/{$relativePath}\n";
echo $asMain ? "Set as the product's main image.\n" : "Added to the product's gallery.\n";
