<?php

declare(strict_types=1);

/**
 * Rewrites every product with import_status = 'imported' into
 * professional storefront copy (via Gemini) and reprices its variants,
 * then flags it as import_status = 'update' for review.
 * Usage: php commands/update-products.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Services\ProductUpdater;

$summary = ProductUpdater::runAll();

printf(
    "processed %d, updated %d, skipped %d\n",
    $summary['processed'],
    $summary['updated'],
    $summary['skipped']
);

if (!empty($summary['errors'])) {
    echo "errors:\n";
    foreach ($summary['errors'] as $error) {
        echo "  - {$error}\n";
    }
}
