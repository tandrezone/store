<?php

declare(strict_types=1);

namespace Store\Services;

use PDO;
use RuntimeException;
use Store\Config\Database;
use Throwable;

/**
 * Takes freshly-imported products (import_status = 'imported') and turns
 * them into storefront-ready listings: a professional name + short/long
 * description via Gemini, and a repriced, customer-friendly price for
 * every variant. Successfully processed products move to import_status =
 * 'update' so an admin reviews them before flipping them to 'approved'.
 *
 * Pricing is deterministic, not LLM-generated — a language model asked to
 * "double this number attractively" is an unreliable source of arithmetic.
 * Only the name/description text comes from Gemini.
 */
class ProductUpdater
{
    public static function runAll(): array
    {
        $pdo = Database::connection();
        $result = ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $productIds = $pdo->query("SELECT id FROM products WHERE import_status = 'imported'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach ($productIds as $productId) {
            $result['processed']++;
            try {
                if (self::updateProduct($pdo, (int) $productId)) {
                    $result['updated']++;
                } else {
                    $result['skipped']++;
                }
            } catch (Throwable $e) {
                $result['errors'][] = "product #{$productId}: " . $e->getMessage();
            }

            // Basic pacing so a large batch doesn't blow through Gemini's
            // free-tier rate limit.
            usleep(300000);
        }

        return $result;
    }

    /**
     * Returns false if the product was skipped (not in 'imported' status
     * anymore, e.g. a concurrent run already touched it). Throws if Gemini
     * or the update itself fails — the product is left untouched so it can
     * be retried on the next run, rather than applying half of the change.
     */
    private static function updateProduct(PDO $pdo, int $productId): bool
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product || $product['import_status'] !== 'imported') {
            return false;
        }

        $copy = self::generateCopy(
            $product['name'],
            $product['short_description'],
            $product['long_description']
        );

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE products
                SET name = :name, short_description = :short, long_description = :long, import_status = 'update'
                WHERE id = :id
            ")->execute([
                'name'  => $copy['name'],
                'short' => $copy['short_description'],
                'long'  => $copy['long_description'],
                'id'    => $productId,
            ]);

            $variants = $pdo->prepare('SELECT id, price FROM product_variants WHERE product_id = :id');
            $variants->execute(['id' => $productId]);

            $reprice = $pdo->prepare('UPDATE product_variants SET price = :price WHERE id = :id');
            foreach ($variants->fetchAll() as $variant) {
                $reprice->execute([
                    'price' => self::attractivePrice((float) $variant['price']),
                    'id'    => $variant['id'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Roughly doubles the price, then rounds down to a "…99" charm-pricing
     * ending so it reads as a deliberate price rather than raw arithmetic.
     * Never lets that rounding drop below a genuine ~1.8x markup.
     */
    private static function attractivePrice(float $cost): float
    {
        if ($cost <= 0.0) {
            return $cost;
        }

        $doubled = $cost * 2;
        $charm = floor($doubled) - 0.01;

        return round(max($charm, $cost * 1.8), 2);
    }

    /**
     * Asks Gemini for a professional name + short/long description and
     * returns it. Throws on any failure (network, bad JSON, missing/empty
     * fields) so the caller leaves the product untouched instead of
     * applying a partial edit.
     */
    private static function generateCopy(string $name, string $shortDescription, string $longDescription): array
    {
        $prompt = <<<PROMPT
        You are a professional e-commerce copywriter rewriting a product
        listing for an online store. Keep it factual — do not invent
        features, specs, or claims beyond what the current text implies.
        Make it read as polished, appealing storefront copy.

        Product name: {$name}
        Current short description: {$shortDescription}
        Current long description: {$longDescription}

        Improve the name only if it needs it (typos, inconsistent
        capitalization, overly technical/supplier-style naming, redundant
        words) — otherwise return it unchanged. Do not change what product
        it refers to.

        Reply with ONLY a compact JSON object, no code fences and no other
        text:
        {"name": "...", "short_description": "...", "long_description": "..."}

        name must be plain text, 180 characters or fewer.
        short_description must be plain text, 280 characters or fewer.
        long_description can be a few sentences.
        PROMPT;

        $reply = (new GeminiClient())->generateText($prompt);
        $parsed = self::parseJsonReply($reply);

        if (!isset($parsed['name'], $parsed['short_description'], $parsed['long_description'])) {
            throw new RuntimeException('Gemini reply did not contain the expected JSON fields.');
        }

        $cleanName = trim(substr((string) $parsed['name'], 0, 180));
        if ($cleanName === '') {
            throw new RuntimeException('Gemini returned an empty name.');
        }

        return [
            'name'               => $cleanName,
            'short_description'  => substr((string) $parsed['short_description'], 0, 280),
            'long_description'   => (string) $parsed['long_description'],
        ];
    }

    private static function parseJsonReply(string $reply): ?array
    {
        $reply = trim($reply);
        $reply = preg_replace('/^```(?:json)?|```$/m', '', $reply) ?? $reply;
        $decoded = json_decode(trim($reply), true);

        return is_array($decoded) ? $decoded : null;
    }
}
