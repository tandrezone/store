<?php

declare(strict_types=1);

/**
 * Example "magic edit" endpoint: sends the product plus a plain-language
 * instruction to Gemini and returns whatever it replies.
 *
 * Deliberately minimal — it does NOT write to the database. Wire up the
 * apply/persist step (and tighten the prompt to return strict JSON) as needed.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Store\Models\Product;
use Store\Services\AdminAuth;
use Store\Services\GeminiClient;

header('Content-Type: application/json');

if (!AdminAuth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$instruction = trim((string) ($_POST['instruction'] ?? ''));

if ($productId <= 0 || $instruction === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A product and an instruction are required.']);
    exit;
}

$product = Product::findForAdmin($productId);
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found.']);
    exit;
}

$prompt = <<<PROMPT
You are editing a product in an online store catalog.

Current product:
name: {$product['name']}
short_description: {$product['short_description']}
long_description: {$product['long_description']}

Requested change: {$instruction}

Reply with only a JSON object containing the fields you changed, using the keys
name, short_description, long_description. Do not wrap it in code fences.
PROMPT;

try {
    $reply = (new GeminiClient())->generateText($prompt);
    echo json_encode(['success' => true, 'suggestion' => $reply]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
