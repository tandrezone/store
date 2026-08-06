<?php

declare(strict_types=1);

namespace Store\Models;

use Store\Config\Database;

class Todo
{
    public const CATEGORIES = ['bug', 'nice_to_have', 'feature'];

    public static function allByCategory(): array
    {
        $rows = Database::connection()
            ->query('SELECT id, category, title, description, is_done FROM todos ORDER BY is_done ASC, created_at DESC')
            ->fetchAll();

        $grouped = ['bug' => [], 'nice_to_have' => [], 'feature' => []];
        foreach ($rows as $row) {
            $grouped[$row['category']][] = $row;
        }
        return $grouped;
    }

    public static function create(string $category, string $title, string $description): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO todos (category, title, description) VALUES (:category, :title, :description)');
        $stmt->execute(['category' => $category, 'title' => $title, 'description' => $description]);
        return (int) $pdo->lastInsertId();
    }

    public static function toggleDone(int $id): void
    {
        Database::connection()
            ->prepare('UPDATE todos SET is_done = NOT is_done WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM todos WHERE id = :id')->execute(['id' => $id]);
    }
}
