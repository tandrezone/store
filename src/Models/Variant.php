<?php

declare(strict_types=1);

namespace Store\Models;

use Store\Config\Database;

class Variant
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT v.id, v.product_id, v.sku, v.label, v.unit, v.price,
                   IF(v.price <= 0, 0, v.stock) AS stock,
                   p.name AS product_name
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = :id AND v.is_active = 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function forProduct(int $productId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT id, sku, label, unit, price, stock, is_active
            FROM product_variants
            WHERE product_id = :product_id
            ORDER BY id ASC
        ");
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Batch version of forProduct() for listing pages — one query for every
     * product instead of one per product. Returns [product_id => variants[]],
     * with an empty array for any product that has none.
     */
    public static function forProducts(array $productIds): array
    {
        $grouped = array_fill_keys($productIds, []);

        if (empty($productIds)) {
            return $grouped;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = Database::connection()->prepare("
            SELECT id, product_id, sku, label, unit, price, stock, is_active
            FROM product_variants
            WHERE product_id IN ({$placeholders})
            ORDER BY product_id ASC, id ASC
        ");
        $stmt->execute(array_values($productIds));

        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    public static function decrementStock(int $id, int $qty): void
    {
        $stmt = Database::connection()->prepare("
            UPDATE product_variants
            SET stock = GREATEST(stock - :qty, 0)
            WHERE id = :id
        ");
        $stmt->execute(['qty' => $qty, 'id' => $id]);
    }
}
