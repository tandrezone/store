<?php

declare(strict_types=1);

namespace Store\Services;

use PDO;
use RuntimeException;
use Store\Config\Database;
use Store\Models\Supplier;
use Store\Services\ImageDownloader;
use Throwable;

/**
 * Imports products from each supplier's list_products_url.
 *
 * Canonical feed shape used internally after normalization:
 * {
 *   "products": [
 *     {
 *       "id": "supplier's own product id — required, used to match on re-import",
 *       "name": "Product name",
 *       "slug": "used as a short description fallback",
 *       "description": "Longer description",
 *       "category": { "name": "Category name (created if it doesn't exist yet)" },
 *       "isActive": true,
 *       "image": "https://supplier.example/photos/main.jpg (optional, main photo)",
 *       "images": ["https://supplier.example/photos/1.jpg (optional, gallery photos)"],
 *       "variants": [
 *         { "sku": "SUP-1", "label": "6", "unit": "pack", "price": 9.99, "stock": 50 }
 *       ]
 *     }
 *   ]
 * }
 *
 * The importer accepts multiple supplier JSON shapes and normalizes common
 * aliases (e.g. products/items/data wrappers, id/product_id, isActive/active,
 * image/image_url, variants/options, etc.) into this canonical shape.
 *
 * Only items with isActive == true are imported. Re-running the import
 * matches existing rows by (supplier_id, external_id) and only updates
 * name/description/pricing/variants for rows that are still import_status
 * = 'imported' — manually created, approved, or flagged (invalid/update)
 * products keep the text and pricing an admin already reviewed.
 *
 * "image" and "images" are the one exception to that protection: they are
 * downloaded via ImageDownloader and synced on every re-import regardless
 * of status, since refreshing photos doesn't undo an admin's review the
 * way overwriting the name/description/price would. The main photo
 * becomes image_path, and the full set (main + gallery, deduped) becomes
 * the images column that feeds the product detail slideshow. Existing
 * image columns are left untouched if none of the URLs for an item can be
 * downloaded, rather than being cleared out.
 *
 * Both fields may be a full URL or a path relative to the supplier's own
 * site (e.g. "/uploads/photo.jpg") — some feeds serve their own uploads
 * that way while linking gallery photos on a third-party host. Relative
 * paths are resolved against list_products_url's origin before download.
 *
 * variant.label and variant.unit are sanitized on the way in — label is
 * stripped to digits only (pack quantity, e.g. "6 pack" -> "6") and unit
 * to letters only (e.g. "5kg" -> "kg") — since suppliers mix the two
 * freely and the storefront displays them as separate fields.
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
        $origin = self::originOf($supplier['list_products_url']);

        foreach ($items as $item) {
            try {
                $outcome = self::importItem($pdo, (int) $supplier['id'], $item, $origin);
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
    * Fetches and decodes the feed, returning a normalized product-item list
    * from multiple supported supplier JSON structures.
     *
     * The hostname is resolved and validated once here, then curl is pinned
     * to that exact IP (CURLOPT_RESOLVE) so a DNS change between validation
     * and the request can't redirect the fetch to an internal address
     * (SSRF / DNS-rebinding). Redirects are not followed for the same reason
     * — point list_products_url at the final URL if the feed ever redirects.
     */
    private static function fetchItems(string $url): array
    {
        $target = UrlGuard::resolvePublicHttpUrl($url);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
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

        $items = self::extractItems($decoded);
        if (empty($items)) {
            throw new RuntimeException('Feed did not contain a product list: ' . $url);
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized[] = self::normalizeItem($item);
        }

        if (empty($normalized)) {
            throw new RuntimeException('Feed product list items had an invalid structure: ' . $url);
        }

        return $normalized;
    }

    /**
     * Returns 'imported', 'updated', or null (skipped) for this item.
     */
    private static function importItem(PDO $pdo, int $supplierId, array $item, string $origin = ''): ?string
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
            $productId = (int) $existing['id'];

            // Images refresh on every re-import, even after admin review —
            // only the fields below (name/description/pricing/variants) are
            // protected once a product leaves import_status = 'imported'.
            self::syncImages($pdo, $productId, $item, $origin);

            if ($existing['import_status'] !== 'imported') {
                // Manually reviewed since the last import — leave everything
                // else alone.
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
                'id' => $productId,
            ]);

            self::syncVariants($pdo, $productId, (array) ($item['variants'] ?? []));

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
        self::syncImages($pdo, $productId, $item, $origin);

        return 'imported';
    }

    /**
     * Downloads the item's "image" (main) and "images" (gallery) URLs and,
     * if at least one succeeds, updates image_path (first downloaded image)
     * and images (the full deduped list) for $productId. Leaves the
     * existing columns untouched if every URL fails or none were provided,
     * rather than clearing out a previously-downloaded set of images.
     */
    private static function syncImages(PDO $pdo, int $productId, array $item, string $origin = ''): void
    {
        $mainUrl = self::resolveImageUrl((string) ($item['image'] ?? ''), $origin);
        $galleryUrls = array_map(
            static fn ($url) => self::resolveImageUrl((string) $url, $origin),
            array_values((array) ($item['images'] ?? []))
        );

        $urls = array_values(array_unique(array_filter(
            array_merge($mainUrl !== '' ? [$mainUrl] : [], $galleryUrls),
            static fn ($url) => $url !== ''
        )));

        $paths = [];
        foreach ($urls as $url) {
            $path = ImageDownloader::download($url, $productId);
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        if (empty($paths)) {
            return;
        }

        $pdo->prepare('UPDATE products SET image_path = :image_path, images = :images WHERE id = :id')
            ->execute([
                'image_path' => $paths[0],
                'images' => json_encode($paths),
                'id' => $productId,
            ]);
    }

    /** Returns "scheme://host[:port]" for $url, or '' if it can't be parsed. */
    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /**
     * Passes an already-absolute URL through unchanged; resolves a path
     * relative to $origin (e.g. "/uploads/photo.jpg" + "https://x.test" ->
     * "https://x.test/uploads/photo.jpg"). Returns '' if $url is empty, or
     * if it's relative and $origin is unknown (unparseable feed URL).
     */
    private static function resolveImageUrl(string $url, string $origin): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return $origin !== '' ? $origin . '/' . ltrim($url, '/') : '';
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

            $label = self::digitsOnly((string) ($variant['label'] ?? ''));
            $unit = self::lettersOnly((string) ($variant['unit'] ?? ''));
            $price = (float) ($variant['price'] ?? 0);
            // Suppliers that don't track stock send 0 or omit the field —
            // treat that as effectively unlimited rather than "out of stock".
            $stock = max(0, (int) ($variant['stock'] ?? 0)) ?: 9999;

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

    /** Strips everything but digits, e.g. "6 pack" -> "6". */
    private static function digitsOnly(string $value): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $value) ?? '';
        return $clean !== '' ? $clean : null;
    }

    /** Strips everything but letters, e.g. "5kg" -> "kg". */
    private static function lettersOnly(string $value): ?string
    {
        $clean = preg_replace('/[^A-Za-z]/', '', $value) ?? '';
        return $clean !== '' ? $clean : null;
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'uncategorized';
    }

    private static function extractItems(array $decoded): array
    {
        foreach (['products', 'items', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return array_values($decoded[$key]);
            }
        }

        return array_values($decoded);
    }

    private static function normalizeItem(array $item): array
    {
        $id = self::firstString($item, ['id', 'product_id', 'productId', 'external_id', 'externalId', 'sku', 'code']);
        $name = self::firstString($item, ['name', 'title', 'product_name', 'productName']);
        $slug = self::firstString($item, ['slug', 'handle', 'url_key', 'urlKey']);
        $description = self::firstString($item, ['description', 'long_description', 'longDescription', 'details']);

        $category = '';
        if (isset($item['category'])) {
            if (is_array($item['category'])) {
                $category = trim((string) ($item['category']['name'] ?? $item['category']['title'] ?? ''));
            } else {
                $category = trim((string) $item['category']);
            }
        }
        if ($category === '') {
            $category = self::firstString($item, ['category_name', 'categoryName', 'department']);
        }

        $image = self::firstString($item, ['image', 'image_url', 'imageUrl', 'main_image', 'mainImage', 'photo']);
        $images = self::firstArray($item, ['images', 'gallery', 'photos', 'image_urls', 'imageUrls']);
        $variants = self::firstArray($item, ['variants', 'options', 'variant_list', 'variantList']);

        if ($variants === [] && (isset($item['price']) || isset($item['cost']) || isset($item['stock']) || isset($item['quantity']) || isset($item['qty']))) {
            $variants = [[
                'sku' => self::firstString($item, ['variant_sku', 'variantSku']),
                'label' => self::firstString($item, ['label', 'size', 'pack', 'pack_size', 'packSize']),
                'unit' => self::firstString($item, ['unit', 'unit_type', 'unitType']),
                'price' => $item['price'] ?? $item['cost'] ?? 0,
                'stock' => $item['stock'] ?? $item['quantity'] ?? $item['qty'] ?? 0,
            ]];
        }

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'category' => ['name' => $category !== '' ? $category : 'Uncategorized'],
            'isActive' => self::normalizeIsActive($item),
            'image' => $image,
            'images' => array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                array_values($images)
            ), static fn (string $v) => $v !== '')),
            'variants' => self::normalizeVariants($variants),
        ];
    }

    private static function normalizeVariants(array $variants): array
    {
        $normalized = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $normalized[] = [
                'sku' => self::firstString($variant, ['sku', 'code']),
                'label' => self::firstString($variant, ['label', 'size', 'pack', 'pack_size', 'packSize']),
                'unit' => self::firstString($variant, ['unit', 'unit_type', 'unitType']),
                'price' => $variant['price'] ?? $variant['cost'] ?? 0,
                'stock' => $variant['stock'] ?? $variant['quantity'] ?? $variant['qty'] ?? $variant['inventory'] ?? 0,
            ];
        }

        return $normalized;
    }

    private static function normalizeIsActive(array $item): bool
    {
        foreach (['isActive', 'active', 'is_active', 'enabled', 'status'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $value = $item[$key];
            if (is_string($value)) {
                $value = strtolower(trim($value));
                if (in_array($value, ['active', 'enabled', 'available', 'published'], true)) {
                    return true;
                }

                return false;
            }

            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return $bool;
            }

            return (bool) $value;
        }

        return true;
    }

    private static function firstString(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $value = trim((string) $item[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function firstArray(array $item, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && is_array($item[$key])) {
                return $item[$key];
            }
        }

        return [];
    }
}
