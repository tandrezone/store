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

    /**
     * Sends an image plus an editing instruction to Gemini's image model
     * and returns the edited image's raw bytes.
     *
     * Throws if the request fails or the reply doesn't contain an image —
     * callers should treat that as "leave the original image alone"
     * rather than retry with the same input.
     */
    public function editImage(string $imageData, string $mimeType, string $prompt): string
    {
        $apiKey = GeminiConfig::apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url = GeminiConfig::baseUrl() . '/models/' . GeminiConfig::imageModel() . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($imageData)]],
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
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini image request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response (HTTP ' . $httpCode . '): ' . $response);
        }

        $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $base64 = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            if (!is_string($base64) || $base64 === '') {
                continue;
            }

            $bytes = base64_decode($base64, true);
            if ($bytes !== false) {
                return $bytes;
            }
        }

        throw new RuntimeException('Gemini reply did not contain an edited image.');
    }
}
