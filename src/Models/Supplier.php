<?php

declare(strict_types=1);

namespace Store\Models;

use Store\Config\Database;

class Supplier
{
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT id, name, list_products_url FROM suppliers ORDER BY name ASC')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, name, list_products_url FROM suppliers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $listProductsUrl): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, list_products_url) VALUES (:name, :url)');
        $stmt->execute(['name' => $name, 'url' => $listProductsUrl]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name, string $listProductsUrl): void
    {
        $stmt = Database::connection()->prepare('UPDATE suppliers SET name = :name, list_products_url = :url WHERE id = :id');
        $stmt->execute(['name' => $name, 'url' => $listProductsUrl, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $id]);
    }
}
