<?php

declare(strict_types=1);

/**
 * Fetches each supplier's list_products_url and imports available products.
 * Usage: php commands/import-products.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Services\ProductImporter;

$summary = ProductImporter::runAll();

if (empty($summary)) {
    echo "No suppliers configured yet — add one in /admin/suppliers.php first.\n";
    exit(0);
}

foreach ($summary as $row) {
    printf(
        "%s: fetched %d, imported %d, updated %d, skipped %d%s\n",
        $row['supplier'],
        $row['fetched'],
        $row['imported'],
        $row['updated'],
        $row['skipped'],
        empty($row['errors']) ? '' : ' — errors: ' . implode('; ', $row['errors'])
    );
}
