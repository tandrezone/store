<?php

declare(strict_types=1);

namespace Store\Models;

use Store\Config\Database;
use Store\Services\DefaultProductImage;

class Product
{
    /**
     * Return active products, optionally filtered by category slug.
     * Each product includes its lowest variant price for the card display.
     */
    public static function allByCategory(?string $categorySlug = null): array
    {
        $pdo = Database::connection();

        $sql = "
            SELECT p.id, p.name, p.short_description, p.image_path,
                   c.name AS category_name, c.slug AS category_slug,
                   MIN(v.price) AS from_price
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1
            WHERE p.is_active = 1 AND p.import_status = 'approved'
        ";

        $params = [];
        if ($categorySlug !== null && $categorySlug !== '') {
            $sql .= " AND c.slug = :slug";
            $params['slug'] = $categorySlug;
        }

        $sql .= " GROUP BY p.id ORDER BY p.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $products = $stmt->fetchAll();
        foreach ($products as &$product) {
            $product['image_path'] = self::resolveImagePathCached($product);
        }

        return $products;
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM products p
            JOIN categories c ON c.id = p.category_id
            WHERE p.id = :id AND p.is_active = 1 AND p.import_status = 'approved'
        ");
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        $images = self::resolveImages($product);
        $product['images'] = $images;
        $product['image_path'] = $images[0];
        $product['variants'] = self::variantsForProduct($id);

        return $product;
    }

    /**
     * Admin lookup: returns a product regardless of status/active flag.
     */
    public static function findForAdmin(int $id): ?array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name, s.name AS supplier_name
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        $product['variants'] = Variant::forProduct($id);

        return $product;
    }

    public static function update(int $id, array $fields): void
    {
        $columns = ['name', 'category_id', 'supplier_id', 'short_description', 'long_description'];
        $set = [];
        $params = ['id' => $id];

        foreach ($columns as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "{$column} = :{$column}";
                $params[$column] = $fields[$column];
            }
        }

        if (empty($set)) {
            return;
        }

        $sql = 'UPDATE products SET ' . implode(', ', $set) . ' WHERE id = :id';
        Database::connection()->prepare($sql)->execute($params);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Falls back to a generated placeholder image when image_path is empty
     * or points to a file that doesn't actually exist on disk.
     */
    /** Generates the placeholder on first use — fine for a single product page. */
    private static function resolveImagePath(array $product): string
    {
        $path = (string) ($product['image_path'] ?? '');

        if ($path !== '' && is_file(__DIR__ . '/../../public/' . $path)) {
            return $path;
        }

        return DefaultProductImage::pathFor($product);
    }

    /**
     * Returns the product detail slideshow images: the decoded `images`
     * column, filtered to paths that still exist on disk (in case a file
     * was moved/deleted after import). Falls back to a single-element
     * array with the regular placeholder/image_path resolution when there
     * are no valid entries, so callers always get at least one image.
     */
    private static function resolveImages(array $product): array
    {
        $decoded = json_decode((string) ($product['images'] ?? ''), true);
        $paths = is_array($decoded) ? $decoded : [];

        $valid = array_values(array_filter(
            $paths,
            static fn ($path) => is_string($path) && $path !== '' && is_file(__DIR__ . '/../../public/' . $path)
        ));

        return !empty($valid) ? $valid : [self::resolveImagePath($product)];
    }

    /**
     * Never generates — used for listing pages so a page with many products
     * missing a placeholder doesn't pay for generating all of them inline.
     * Run commands/regenerate-images.php to keep the cache warm.
     */
    private static function resolveImagePathCached(array $product): string
    {
        $path = (string) ($product['image_path'] ?? '');

        if ($path !== '' && is_file(__DIR__ . '/../../public/' . $path)) {
            return $path;
        }

        return DefaultProductImage::cachedPathFor($product);
    }

    public static function variantsForProduct(int $productId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT id, sku, label, unit, price, IF(price <= 0, 0, stock) AS stock
            FROM product_variants
            WHERE product_id = :product_id AND is_active = 1
            ORDER BY id ASC
        ");
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll();
    }
}
