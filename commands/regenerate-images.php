<?php

declare(strict_types=1);

/**
 * Ensures every product without a real photo has an up-to-date generated
 * placeholder image. By default this only fills in gaps: products with no
 * image_path, or whose cached placeholder is missing on disk (e.g. after a
 * rename, since the cache filename is derived from the product name).
 *
 * --force also redraws placeholders that are already cached, for products
 * that still don't have a real photo.
 *
 * Usage: php commands/regenerate-images.php [--force]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Config\Database;
use Store\Services\DefaultProductImage;

$force = in_array('--force', $argv, true);

$pdo = Database::connection();
$products = $pdo->query('SELECT id, name, image_path FROM products')->fetchAll();

$generated = 0;
$skipped = 0;

foreach ($products as $product) {
    $imagePath = (string) ($product['image_path'] ?? '');
    $hasRealImage = $imagePath !== '' && is_file(__DIR__ . '/../public/' . $imagePath);

    if ($hasRealImage) {
        $skipped++;
        continue;
    }

    if ($force) {
        DefaultProductImage::forget($product);
    }

    DefaultProductImage::pathFor($product);
    $generated++;
    echo "generated #{$product['id']} — {$product['name']}\n";
}

printf("done: %d generated, %d skipped (already have a real photo)\n", $generated, $skipped);
