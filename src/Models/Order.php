<?php

declare(strict_types=1);

namespace Store\Models;

use PDOException;
use RuntimeException;
use Store\Config\Database;
use Throwable;

class Order
{
    public const STATUSES = ['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled'];

    /**
     * Admin listing, optionally filtered by status, newest first.
     */
    public static function allByStatus(?string $status = null): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT id, order_number, email, ship_name, status, payment_status, total, created_at FROM orders';
        $params = [];

        if ($status !== null && $status !== '' && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :id');
        $items->execute(['id' => $order['id']]);
        $order['items'] = $items->fetchAll();

        return $order;
    }

    public static function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }

        $stmt = Database::connection()->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);

        return true;
    }

    /**
     * Creates an order + its line items from the current cart contents.
     * $shipping keys: name, address1, address2, city, state, postal_code, country, email, phone
     * $items: array of cart items as returned by CartService::getItems()
     */
    public static function create(array $shipping, array $items): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stockStmt = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id FOR UPDATE');

            $subtotal = 0.0;
            foreach ($items as $item) {
                $stockStmt->execute(['id' => $item['variant_id']]);
                $available = $stockStmt->fetchColumn();

                if ($available === false) {
                    throw new RuntimeException($item['product_name'] . ' is no longer available.');
                }
                if ((int) $available < (int) $item['quantity']) {
                    throw new RuntimeException(
                        'Not enough stock for ' . $item['product_name'] . ' — only ' . (int) $available . ' left.'
                    );
                }

                $subtotal += (float) $item['price'] * (int) $item['quantity'];
            }
            $subtotal = round($subtotal, 2);
            $total = $subtotal; // extend here for shipping cost / taxes if needed

            $orderNumber = self::generateOrderNumber();

            $stmt = $pdo->prepare("
                INSERT INTO orders
                    (order_number, email, phone, ship_name, ship_address1, ship_address2,
                     ship_city, ship_state, ship_postal_code, ship_country, subtotal, total)
                VALUES
                    (:order_number, :email, :phone, :ship_name, :address1, :address2,
                     :city, :state, :postal_code, :country, :subtotal, :total)
            ");
            $stmt->execute([
                'order_number' => $orderNumber,
                'email'        => $shipping['email'],
                'phone'        => $shipping['phone'] ?: null,
                'ship_name'    => $shipping['name'],
                'address1'     => $shipping['address1'],
                'address2'     => $shipping['address2'] ?: null,
                'city'         => $shipping['city'],
                'state'        => $shipping['state'] ?: null,
                'postal_code'  => $shipping['postal_code'],
                'country'      => $shipping['country'],
                'subtotal'     => $subtotal,
                'total'        => $total,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("
                INSERT INTO order_items
                    (order_id, variant_id, product_name, label, unit, unit_price, quantity, line_total)
                VALUES
                    (:order_id, :variant_id, :product_name, :label, :unit, :unit_price, :quantity, :line_total)
            ");

            foreach ($items as $item) {
                $lineTotal = round((float) $item['price'] * (int) $item['quantity'], 2);
                $itemStmt->execute([
                    'order_id'     => $orderId,
                    'variant_id'   => $item['variant_id'],
                    'product_name' => $item['product_name'],
                    'label'        => $item['label'] !== null ? (string) $item['label'] : null,
                    'unit'         => $item['unit'] !== null ? (string) $item['unit'] : null,
                    'unit_price'   => $item['price'],
                    'quantity'     => $item['quantity'],
                    'line_total'   => $lineTotal,
                ]);
            }

            $pdo->commit();

            return ['id' => $orderId, 'order_number' => $orderNumber, 'total' => $total];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function findByNumber(string $orderNumber): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :n");
        $stmt->execute(['n' => $orderNumber]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        $items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :id");
        $items->execute(['id' => $order['id']]);
        $order['items'] = $items->fetchAll();

        return $order;
    }

    public static function updatePaymentStatus(string $orderNumber, string $paymentStatus): void
    {
        $pdo = Database::connection();
        $orderStatus = match ($paymentStatus) {
            'paid'    => 'paid',
            'failed', 'expired' => 'cancelled',
            default   => 'pending',
        };

        $stmt = $pdo->prepare("
            UPDATE orders
            SET payment_status = :payment_status, status = :status
            WHERE order_number = :order_number
        ");
        $stmt->execute([
            'payment_status' => $paymentStatus,
            'status'         => $orderStatus,
            'order_number'   => $orderNumber,
        ]);
    }

    private static function generateOrderNumber(): string
    {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
