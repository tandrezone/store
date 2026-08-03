<?php

declare(strict_types=1);

namespace Store\Config;

/**
 * OxaPay merchant configuration.
 * Get your merchant API key from the OxaPay dashboard -> Merchant API.
 * Docs: https://docs.oxapay.com/
 *
 * Double-check field names/endpoints against the current docs before
 * going live — payment provider APIs change their contracts over time.
 */
class OxaPayConfig
{
    public static function get(): array
    {
        return [
            'merchant_api_key' => getenv('OXAPAY_MERCHANT_KEY') ?: 'YOUR_MERCHANT_API_KEY',
            'base_url'         => 'https://api.oxapay.com',
            'sandbox'          => filter_var(getenv('OXAPAY_SANDBOX') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'currency'         => 'EUR',
            'lifetime_minutes' => 60,
            'callback_url'     => getenv('APP_URL') . '/payment/callback.php',
            'return_url'       => getenv('APP_URL') . '/order-confirmation.php',
        ];
    }
}
