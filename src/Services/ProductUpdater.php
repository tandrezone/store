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
 *
 * Product photos are also cleaned up via Gemini's image model: any
 * supplier-added text (watermarks, price stickers, logos baked into the
 * shot) is edited out in place. This runs best-effort — an image that
 * fails to edit (bad reply, network error, non-image file) is just left
 * as downloaded rather than failing the whole product update, since the
 * name/description/price rewrite is the part a re-run can't easily redo
 * once the product leaves 'imported' status.
 */
class ProductUpdater
{
    private const IMAGE_TEXT_REMOVAL_PROMPT = <<<PROMPT
        Remove all text, watermarks, price stickers, labels, and logos
        from this product photo — including any logo or brand mark
        printed directly on the product or its packaging, not just ones
        overlaid on top of the photo. Keep the product's shape, packaging
        design, colors, proportions, and background otherwise unchanged,
        cleanly filling in whatever was underneath each removed element.
        Return the edited image.
        PROMPT;

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

        self::removeTextFromImages($product);

        return true;
    }

    /**
     * Best-effort: edits every image downloaded for this product to erase
     * any overlaid text, overwriting the file in place so image_path/images
     * (and every page that links them) keep pointing at the same path.
     * Skips silently on any per-image failure.
     */
    private static function removeTextFromImages(array $product): void
    {
        foreach (self::imagePathsFor($product) as $relativePath) {
            try {
                self::removeTextFromImage($relativePath);
            } catch (Throwable $e) {
                // Leave the original image untouched — a failed edit isn't
                // worth blocking or retrying the product update over.
            }

            // Same rate-limit pacing as the copy-generation loop, since this
            // adds another Gemini call per image on top of it.
            usleep(300000);
        }
    }

    /** Main image_path plus the gallery images column, deduped. */
    private static function imagePathsFor(array $product): array
    {
        $paths = [];

        $main = trim((string) ($product['image_path'] ?? ''));
        if ($main !== '') {
            $paths[] = $main;
        }

        $gallery = json_decode((string) ($product['images'] ?? ''), true);
        foreach ((array) $gallery as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Sends the image at $relativePath (relative to /public) to Gemini and
     * overwrites it with the edited result. Throws on any failure — the
     * caller treats that as "leave this one image alone".
     */
    private static function removeTextFromImage(string $relativePath): void
    {
        $fullPath = __DIR__ . '/../../public/' . $relativePath;
        if (!is_file($fullPath)) {
            return;
        }

        $data = file_get_contents($fullPath);
        if ($data === false) {
            throw new RuntimeException("Could not read {$relativePath}.");
        }

        $mime = @getimagesizefromstring($data)['mime'] ?? null;
        if ($mime === null) {
            throw new RuntimeException("{$relativePath} is not a readable image.");
        }

        $edited = (new GeminiClient())->editImage($data, $mime, self::IMAGE_TEXT_REMOVAL_PROMPT);
        if (@getimagesizefromstring($edited) === false) {
            throw new RuntimeException("Gemini's reply for {$relativePath} was not a valid image.");
        }

        // Write to a temp file and rename into place — rename() is atomic,
        // so a concurrent request never sees a half-written file.
        $tmpPath = $fullPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmpPath, $edited);
        rename($tmpPath, $fullPath);
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
