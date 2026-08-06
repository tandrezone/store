<?php

declare(strict_types=1);

namespace Store\Models;

use Store\Config\Database;

class Category
{
    public static function all(): array
    {
        return Database::connection()
            ->query("SELECT id, name, slug FROM categories ORDER BY name ASC")
            ->fetchAll();
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare("SELECT id, name, slug FROM categories WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Admin listing: every category plus how many products reference it. */
    public static function allWithProductCounts(): array
    {
        return Database::connection()->query("
            SELECT c.id, c.name, c.slug, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id
            GROUP BY c.id, c.name, c.slug
            ORDER BY c.name ASC
        ")->fetchAll();
    }

    public static function create(string $name): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug)');
        $stmt->execute(['name' => $name, 'slug' => self::uniqueSlug($name)]);
        return (int) $pdo->lastInsertId();
    }

    /** Renames a category. The slug is set once at creation and never changes, so existing storefront links stay valid. */
    public static function update(int $id, string $name): void
    {
        $stmt = Database::connection()->prepare('UPDATE categories SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $id]);
    }

    private static function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($base === '') {
            $base = 'category';
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT 1 FROM categories WHERE slug = :slug');

        $slug = $base;
        for ($suffix = 2; true; $suffix++) {
            $stmt->execute(['slug' => $slug]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = "{$base}-{$suffix}";
        }
    }
}
