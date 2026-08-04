<?php

declare(strict_types=1);

namespace Store\Services;

use RuntimeException;
use Store\Config\GeminiConfig;

/**
 * Minimal example client for the Gemini API.
 * Docs: https://ai.google.dev/api/generate-content
 *
 * This is intentionally bare — generateText() sends a prompt and returns
 * the plain text reply. Build the actual "magic edit" prompt/response
 * parsing (e.g. asking for structured JSON edits to a product) on top
 * of this call.
 */
class GeminiClient
{
    /**
     * Sends a single prompt and returns Gemini's plain text reply.
     */
    public function generateText(string $prompt): string
    {
        $apiKey = GeminiConfig::apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url = GeminiConfig::baseUrl() . '/models/' . GeminiConfig::model() . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response (HTTP ' . $httpCode . '): ' . $response);
        }

        return (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}
