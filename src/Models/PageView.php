<?php

declare(strict_types=1);

namespace Store\Models;

use PDO;
use Store\Config\Database;
use Throwable;

class PageView
{
    public const EVENT_TYPES = ['visit', 'product_view', 'checkout_shipping', 'checkout_payment'];

    /**
     * Records a tracking event, keyed by the visitor's PHP session id.
     * Never throws — a broken analytics insert must never take down a
     * storefront page.
     */
    public static function record(string $eventType, ?int $productId, string $path): void
    {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        try {
            $stmt = Database::connection()->prepare('
                INSERT INTO page_views (session_id, event_type, product_id, path)
                VALUES (:session_id, :event_type, :product_id, :path)
            ');
            $stmt->execute([
                'session_id' => session_id(),
                'event_type' => $eventType,
                'product_id' => $productId,
                'path'       => substr($path, 0, 255),
            ]);
        } catch (Throwable $e) {
            // Analytics must never break the page it's tracking.
        }
    }

    /** Totals + distinct visitors per event type, over the last N days. */
    public static function summary(int $days): array
    {
        $stmt = Database::connection()->prepare('
            SELECT event_type, COUNT(*) AS total, COUNT(DISTINCT session_id) AS unique_sessions
            FROM page_views
            WHERE created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY event_type
        ');
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $summary = [];
        foreach (self::EVENT_TYPES as $type) {
            $summary[$type] = ['total' => 0, 'unique_sessions' => 0];
        }
        foreach ($stmt->fetchAll() as $row) {
            $summary[$row['event_type']] = [
                'total' => (int) $row['total'],
                'unique_sessions' => (int) $row['unique_sessions'],
            ];
        }

        return $summary;
    }

    /** Daily visit counts for the last N days, oldest first, zero-filled. */
    public static function dailyVisits(int $days): array
    {
        $stmt = Database::connection()->prepare("
            SELECT DATE(created_at) AS day, COUNT(*) AS total, COUNT(DISTINCT session_id) AS unique_sessions
            FROM page_views
            WHERE event_type = 'visit' AND created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY DATE(created_at)
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = ['total' => (int) $row['total'], 'unique_sessions' => (int) $row['unique_sessions']];
        }

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $series[] = [
                'day' => $day,
                'total' => $byDay[$day]['total'] ?? 0,
                'unique_sessions' => $byDay[$day]['unique_sessions'] ?? 0,
            ];
        }

        return $series;
    }

    /** Most-viewed products over the last N days, highest views first. */
    public static function topProducts(int $days, int $limit): array
    {
        $stmt = Database::connection()->prepare("
            SELECT pv.product_id, p.name, COUNT(*) AS views, COUNT(DISTINCT pv.session_id) AS unique_sessions
            FROM page_views pv
            JOIN products p ON p.id = pv.product_id
            WHERE pv.event_type = 'product_view' AND pv.created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY pv.product_id, p.name
            ORDER BY views DESC
            LIMIT :limit
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Most-visited paths over the last N days, highest visits first. */
    public static function topPaths(int $days, int $limit): array
    {
        $stmt = Database::connection()->prepare("
            SELECT path, COUNT(*) AS total, COUNT(DISTINCT session_id) AS unique_sessions
            FROM page_views
            WHERE event_type = 'visit' AND created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY path
            ORDER BY total DESC
            LIMIT :limit
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
