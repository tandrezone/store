<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Store\Config\Database;
use Store\Models\Order;
use Store\Models\Variant;

/**
 * OxaPay sends a POST with JSON body on each status change
 * ("paying" then "paid", or "failed"/"expired").
 * Must respond HTTP 200 with body "ok" or OxaPay will retry.
 *
 * IMPORTANT: before going live, verify the current signature/HMAC
 * validation scheme in OxaPay's docs and check it here before trusting
 * the payload — this stub only reads the body.
 */

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['order_id']) || empty($data['status'])) {
    http_response_code(400);
    echo 'invalid payload';
    exit;
}

$orderNumber = (string) $data['order_id'];
$status = (string) $data['status'];
$trackId = (string) ($data['track_id'] ?? '');
$amount = (float) ($data['amount'] ?? 0);
$currency = (string) ($data['currency'] ?? '');

$order = Order::findByNumber($orderNumber);

if ($order) {
    $pdo = Database::connection();

    $stmt = $pdo->prepare("
        INSERT INTO payments (order_id, track_id, status, amount, currency, raw_response)
        VALUES (:order_id, :track_id, :status, :amount, :currency, :raw)
    ");
    $stmt->execute([
        'order_id' => $order['id'],
        'track_id' => $trackId,
        'status'   => $status,
        'amount'   => $amount,
        'currency' => $currency,
        'raw'      => $raw,
    ]);

    Order::updatePaymentStatus($orderNumber, $status);

    if ($status === 'paid') {
        foreach ($order['items'] as $item) {
            Variant::decrementStock((int) $item['variant_id'], (int) $item['quantity']);
        }
        // TODO: send confirmation email to $order['email'] here.
    }
}

http_response_code(200);
echo 'ok';
