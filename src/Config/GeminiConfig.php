<?php

declare(strict_types=1);

namespace Store\Config;

/**
 * Google Gemini API configuration for the admin "magic edit" example call.
 * Get an API key from https://aistudio.google.com/apikey and set it as
 * the GEMINI_API_KEY environment variable — no key is baked in here.
 */
class GeminiConfig
{
    public static function apiKey(): string
    {
        return getenv('GEMINI_API_KEY') ?: '';
    }

    public static function model(): string
    {
        return getenv('GEMINI_MODEL') ?: 'gemini-flash-latest';
    }

    public static function baseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }
}
