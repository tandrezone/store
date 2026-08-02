<?php

declare(strict_types=1);

namespace Store\Services;

use RuntimeException;

/**
 * Minimal OxaPay merchant API client.
 * Docs: https://docs.oxapay.com/api-reference/payment/generate-invoice
 *
 * NOTE: Payment provider APIs evolve. Before going live, re-check the
 * current field names/response shape against OxaPay's live docs and
 * adjust this class if anything has changed.
 */
class OxaPayClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Creates a payment invoice for an order.
     * Returns the decoded API response (includes a payment link + track_id on success).
     */
    public function createInvoice(string $orderNumber, float $amount, string $email, string $description = ''): array
    {
        $payload = [
            'amount'       => $amount,
            'currency'     => $this->config['currency'],
            'lifetime'     => $this->config['lifetime_minutes'],
            'order_id'     => $orderNumber,
            'email'        => $email,
            'description'  => $description ?: ('Order ' . $orderNumber),
            'callback_url' => $this->config['callback_url'],
            'return_url'   => $this->config['return_url'],
            'sandbox'      => $this->config['sandbox'],
        ];

        return $this->request('POST', '/v1/payment/invoice', $payload);
    }

    /** Retrieve payment details by track_id — useful for reconciliation. */
    public function getPaymentInfo(string $trackId): array
    {
        return $this->request('GET', '/v1/payment/' . urlencode($trackId));
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim($this->config['base_url'], '/') . $path;

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'merchant_api_key: ' . $this->config['merchant_api_key'],
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('OxaPay request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OxaPay returned an unexpected response (HTTP ' . $httpCode . ')');
        }

        return $decoded;
    }
}
