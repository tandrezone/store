<?php

declare(strict_types=1);

namespace Store\Services;

use PDO;
use Store\Config\Database;

class CartService
{
    private PDO $pdo;
    private string $sessionId;

    public function __construct()
    {
        $this->pdo = Database::connection();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->sessionId = session_id();
        $this->ensureCartRow();
    }

    private function ensureCartRow(): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM carts WHERE session_id = :sid");
        $stmt->execute(['sid' => $this->sessionId]);
        if (!$stmt->fetch()) {
            $ins = $this->pdo->prepare("INSERT INTO carts (session_id) VALUES (:sid)");
            $ins->execute(['sid' => $this->sessionId]);
        }
    }

    private function cartId(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM carts WHERE session_id = :sid");
        $stmt->execute(['sid' => $this->sessionId]);
        return (int) $stmt->fetchColumn();
    }

    /** Add an item, or increase quantity if the variant is already in the cart. */
    public function addItem(int $variantId, int $quantity): void
    {
        $quantity = max(1, $quantity);
        $cartId = $this->cartId();

        $stmt = $this->pdo->prepare("
            INSERT INTO cart_items (cart_id, variant_id, quantity)
            VALUES (:cart_id, :variant_id, :qty)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        $stmt->execute(['cart_id' => $cartId, 'variant_id' => $variantId, 'qty' => $quantity]);
    }

    public function updateItem(int $variantId, int $quantity): void
    {
        $cartId = $this->cartId();

        if ($quantity <= 0) {
            $this->removeItem($variantId);
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE cart_items SET quantity = :qty
            WHERE cart_id = :cart_id AND variant_id = :variant_id
        ");
        $stmt->execute(['qty' => $quantity, 'cart_id' => $cartId, 'variant_id' => $variantId]);
    }

    public function removeItem(int $variantId): void
    {
        $cartId = $this->cartId();
        $stmt = $this->pdo->prepare("
            DELETE FROM cart_items WHERE cart_id = :cart_id AND variant_id = :variant_id
        ");
        $stmt->execute(['cart_id' => $cartId, 'variant_id' => $variantId]);
    }

    /** Returns cart line items joined with product/variant details. */
    public function getItems(): array
    {
        $cartId = $this->cartId();
        $stmt = $this->pdo->prepare("
            SELECT ci.variant_id, ci.quantity,
                   v.sku, v.label, v.unit, v.price, IF(v.price <= 0, 0, v.stock) AS stock,
                   p.id AS product_id, p.name AS product_name, p.image_path
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            WHERE ci.cart_id = :cart_id
            ORDER BY ci.id ASC
        ");
        $stmt->execute(['cart_id' => $cartId]);
        return $stmt->fetchAll();
    }

    public function getTotal(): float
    {
        $total = 0.0;
        foreach ($this->getItems() as $item) {
            $total += (float) $item['price'] * (int) $item['quantity'];
        }
        return round($total, 2);
    }

    public function clear(): void
    {
        $cartId = $this->cartId();
        $stmt = $this->pdo->prepare("DELETE FROM cart_items WHERE cart_id = :cart_id");
        $stmt->execute(['cart_id' => $cartId]);
    }
}
