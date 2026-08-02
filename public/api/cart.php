<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Store\Models\Variant;
use Store\Services\CartService;

header('Content-Type: application/json');

$cart = new CartService();
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? 'get')
    : ($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'add':
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 1);

            $variant = Variant::find($variantId);
            if (!$variant) {
                throw new InvalidArgumentException('That option is not available.');
            }
            if ($variant['stock'] < $quantity) {
                throw new InvalidArgumentException('Not enough stock available.');
            }

            $cart->addItem($variantId, max(1, $quantity));
            break;

        case 'update':
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $cart->updateItem($variantId, $quantity);
            break;

        case 'remove':
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $cart->removeItem($variantId);
            break;

        case 'get':
            // fall through to response below
            break;

        default:
            throw new InvalidArgumentException('Unknown cart action.');
    }

    $items = $cart->getItems();
    $count = array_sum(array_column($items, 'quantity'));

    echo json_encode([
        'success' => true,
        'items'   => $items,
        'count'   => $count,
        'total'   => $cart->getTotal(),
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
