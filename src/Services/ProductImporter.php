<?php

declare(strict_types=1);

namespace Store\Services;

use PDO;
use RuntimeException;
use Store\Config\Database;
use Store\Models\Supplier;
use Throwable;

/**
 * Imports products from each supplier's list_products_url.
 *
 * Expected feed shape, fetched from list_products_url:
 * {
 *   "products": [
 *     {
 *       "id": "supplier's own product id — required, used to match on re-import",
 *       "name": "Product name",
 *       "slug": "used as a short description fallback",
 *       "description": "Longer description",
 *       "category": { "name": "Category name (created if it doesn't exist yet)" },
 *       "isActive": true,
 *       "variants": [
 *         { "sku": "SUP-1", "label": "6", "unit": "pack", "price": 9.99, "stock": 50 }
 *       ]
 *     }
 *   ]
 * }
 *
 * Only items with isActive == true are imported. Re-running the import
 * matches existing rows by (supplier_id, external_id) and only updates
 * rows that are still import_status = 'imported' — manually created,
 * approved, or flagged (invalid/update) products are never touched by
 * an automated run.
 */
class ProductImporter
{
    public static function runAll(): array
    {
        $summary = [];
        foreach (Supplier::all() as $supplier) {
            $summary[] = [
                'supplier' => $supplier['name'],
            ] + self::runForSupplier($supplier);
        }

        return $summary;
    }

    public static function runForSupplier(array $supplier): array
    {
        $result = ['fetched' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $items = self::fetchItems($supplier['list_products_url']);
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
            return $result;
        }

        $result['fetched'] = count($items);
        $pdo = Database::connection();

        foreach ($items as $item) {
            try {
                $outcome = self::importItem($pdo, (int) $supplier['id'], $item);
                if ($outcome !== null) {
                    $result[$outcome]++;
                } else {
                    $result['skipped']++;
                }
            } catch (Throwable $e) {
                $result['errors'][] = (string) ($item['id'] ?? $item['name'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Fetches and decodes the feed, returning the flat list of product items
     * regardless of whether the feed wraps them in a "products" key or not.
     */
    private static function fetchItems(string $url): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            throw new RuntimeException('Could not fetch feed from ' . $url . ($error ? ': ' . $error : ' (HTTP ' . $httpCode . ')'));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Feed did not return valid JSON: ' . $url);
        }

        $items = $decoded['products'] ?? $decoded;
        if (!is_array($items)) {
            throw new RuntimeException('Feed did not contain a product list: ' . $url);
        }

        return $items;
    }

    /**
     * Returns 'imported', 'updated', or null (skipped) for this item.
     */
    private static function importItem(PDO $pdo, int $supplierId, array $item): ?string
    {
        $externalId = trim((string) ($item['id'] ?? ''));
        $isActive = filter_var($item['isActive'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($externalId === '' || !$isActive) {
            return null;
        }

        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Item has no name');
        }

        $shortDescription = substr(trim((string) ($item['slug'] ?? $name)), 0, 280);
        $longDescription = trim((string) ($item['description'] ?? $shortDescription));
        $categoryId = self::resolveCategoryId($pdo, (string) ($item['category']['name'] ?? 'Uncategorized'));

        $stmt = $pdo->prepare('SELECT id, import_status FROM products WHERE supplier_id = :sid AND supplier_external_id = :eid');
        $stmt->execute(['sid' => $supplierId, 'eid' => $externalId]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['import_status'] !== 'imported') {
                // Manually reviewed since the last import — leave it alone.
                return null;
            }

            $pdo->prepare(
                'UPDATE products SET category_id = :cat, name = :name, short_description = :short,
                 long_description = :long, import_status = "imported" WHERE id = :id'
            )->execute([
                'cat' => $categoryId,
                'name' => $name,
                'short' => $shortDescription,
                'long' => $longDescription,
                'id' => $existing['id'],
            ]);

            self::syncVariants($pdo, (int) $existing['id'], (array) ($item['variants'] ?? []));

            return 'updated';
        }

        $pdo->prepare(
            'INSERT INTO products (category_id, supplier_id, supplier_external_id, name, short_description,
             long_description, import_status) VALUES (:cat, :sid, :eid, :name, :short, :long, "imported")'
        )->execute([
            'cat' => $categoryId,
            'sid' => $supplierId,
            'eid' => $externalId,
            'name' => $name,
            'short' => $shortDescription,
            'long' => $longDescription,
        ]);

        $productId = (int) $pdo->lastInsertId();
        self::syncVariants($pdo, $productId, (array) ($item['variants'] ?? []));

        return 'imported';
    }

    private static function resolveCategoryId(PDO $pdo, string $categoryName): int
    {
        $categoryName = trim($categoryName) !== '' ? trim($categoryName) : 'Uncategorized';
        $slug = self::slugify($categoryName);

        $stmt = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug)')
            ->execute(['name' => $categoryName, 'slug' => $slug]);

        return (int) $pdo->lastInsertId();
    }

    private static function syncVariants(PDO $pdo, int $productId, array $variants): void
    {
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $sku = trim((string) ($variant['sku'] ?? ''));
            if ($sku === '') {
                $sku = 'SUP-' . $productId . '-' . ($index + 1);
            }

            $label = trim((string) ($variant['label'] ?? '')) ?: null;
            $unit = trim((string) ($variant['unit'] ?? '')) ?: null;
            $price = (float) ($variant['price'] ?? 0);
            $stock = max(0, (int) ($variant['stock'] ?? 0));

            $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE sku = :sku');
            $stmt->execute(['sku' => $sku]);
            $variantId = $stmt->fetchColumn();

            if ($variantId !== false) {
                $pdo->prepare(
                    'UPDATE product_variants SET label = :label, unit = :unit, price = :price, stock = :stock
                     WHERE id = :id'
                )->execute(['label' => $label, 'unit' => $unit, 'price' => $price, 'stock' => $stock, 'id' => $variantId]);
                continue;
            }

            $pdo->prepare(
                'INSERT INTO product_variants (product_id, sku, label, unit, price, stock)
                 VALUES (:pid, :sku, :label, :unit, :price, :stock)'
            )->execute([
                'pid' => $productId,
                'sku' => $sku,
                'label' => $label,
                'unit' => $unit,
                'price' => $price,
                'stock' => $stock,
            ]);
        }
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'uncategorized';
    }
}
