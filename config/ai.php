<?php

/**
 * IA OpenRouter — modelos separados por tarea.
 *
 * .env:
 *   OPENROUTER_API_KEY=
 *   OPENROUTER_CHAT_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_INVOICE_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_VISION_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_MODEL=  (legacy, alias de chat si no hay OPENROUTER_CHAT_MODEL)
 */

$gemini = 'google/gemini-2.5-flash';
$appCfg = require __DIR__ . '/app.php';
$appUrl = trim((string)($appCfg['app']['url'] ?? ''));
$appName = trim((string)($appCfg['app']['name'] ?? 'Seri ERP'));

return [
    'ai' => [
        'enabled' => true,
        'provider' => 'openrouter',
        'base_url' => 'https://openrouter.ai/api/v1',
        'api_key' => getenv('OPENROUTER_API_KEY') ?: '',
        'default_model' => getenv('OPENROUTER_DEFAULT_MODEL') ?: $gemini,
        // Chat asistente Seri
        'chat_model' => getenv('OPENROUTER_CHAT_MODEL')
            ?: (getenv('OPENROUTER_MODEL') ?: $gemini),
        // Facturas: texto / JSON (Compras)
        'invoice_model' => getenv('OPENROUTER_INVOICE_MODEL') ?: $gemini,
        // Facturas: foto / PDF escaneado
        'vision_model' => getenv('OPENROUTER_VISION_MODEL') ?: $gemini,
        // Alias legacy (no usar en código nuevo)
        'model' => getenv('OPENROUTER_CHAT_MODEL')
            ?: (getenv('OPENROUTER_MODEL') ?: $gemini),
        'timeout' => (int)(getenv('OPENROUTER_TIMEOUT') ?: 90),
        'vision_timeout' => (int)(getenv('OPENROUTER_VISION_TIMEOUT') ?: 90),
        'invoice_timeout' => (int)(getenv('OPENROUTER_INVOICE_TIMEOUT') ?: 90),
        'max_tokens' => (int)(getenv('OPENROUTER_MAX_TOKENS') ?: 1200),
        'invoice_max_tokens' => (int)(getenv('OPENROUTER_INVOICE_MAX_TOKENS') ?: 4000),
        'temperature' => (float)(getenv('OPENROUTER_TEMPERATURE') ?: 0.4),
        'site_url' => getenv('OPENROUTER_SITE_URL') ?: ($appUrl !== '' ? $appUrl : 'http://localhost'),
        'site_name' => getenv('OPENROUTER_SITE_NAME') ?: ($appName !== '' ? $appName : 'Seri ERP'),
        'fallback_local' => (getenv('OPENROUTER_FALLBACK_LOCAL') ?: '1') !== '0',
        'max_history' => (int)(getenv('OPENROUTER_MAX_HISTORY') ?: 10),
        'image_enabled' => false,
        'image_model' => 'google/gemini-2.5-flash-image-preview',
        'image_max_size' => '1024x1024',
    ],
];
