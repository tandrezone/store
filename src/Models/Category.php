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
}
