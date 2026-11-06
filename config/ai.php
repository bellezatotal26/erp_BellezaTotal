<?php

/**
 * IA OpenRouter — modelos separados por tarea.
 *
 * .env recomendado (visión + PDF escaneado; IDs vigentes en OpenRouter):
 *   OPENROUTER_API_KEY=
 *   OPENROUTER_CHAT_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_INVOICE_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_VISION_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_PDF_MODEL=google/gemini-2.5-flash
 *   OPENROUTER_INVOICE_MAX_TOKENS=4000
 *   OPENROUTER_VISION_TIMEOUT=60
 *   OPENROUTER_SSL_VERIFY=0
 *
 * Nota: google/gemini-2.0-flash ya NO existe en OpenRouter (error "not a valid model ID").
 * AiService remapea ese ID viejo automáticamente si queda en .env.
 */

$gemini = 'google/gemini-2.5-flash';
// Mismo Flash multimodal: texto, foto y PDF (nativo o escaneado).
$visionDefault = 'google/gemini-2.5-flash';
$pdfDefault = 'google/gemini-2.5-flash';
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
        // Facturas: foto / PDF escaneado (debe soportar visión/imagen)
        'vision_model' => getenv('OPENROUTER_VISION_MODEL') ?: $visionDefault,
        // PDF binario cuando el hosting no rasteriza (Imagick/GS)
        'pdf_model' => getenv('OPENROUTER_PDF_MODEL') ?: $pdfDefault,
        // Alias legacy (no usar en código nuevo)
        'model' => getenv('OPENROUTER_CHAT_MODEL')
            ?: (getenv('OPENROUTER_MODEL') ?: $gemini),
        'timeout' => (int)(getenv('OPENROUTER_TIMEOUT') ?: 60),
        'vision_timeout' => (int)(getenv('OPENROUTER_VISION_TIMEOUT') ?: 60),
        'invoice_timeout' => (int)(getenv('OPENROUTER_INVOICE_TIMEOUT') ?: 60),
        'max_tokens' => (int)(getenv('OPENROUTER_MAX_TOKENS') ?: 1200),
        'invoice_max_tokens' => (int)(getenv('OPENROUTER_INVOICE_MAX_TOKENS') ?: 4000),
        'temperature' => (float)(getenv('OPENROUTER_TEMPERATURE') ?: 0.4),
        'site_url' => getenv('OPENROUTER_SITE_URL') ?: ($appUrl !== '' ? $appUrl : 'http://localhost'),
        'site_name' => getenv('OPENROUTER_SITE_NAME') ?: ($appName !== '' ? $appName : 'Seri ERP'),
        'fallback_local' => (getenv('OPENROUTER_FALLBACK_LOCAL') ?: '1') !== '0',
        // Hostings cPanel sin CA: por defecto NO verificar (si no, OpenRouter falla con “local issuer”).
        'ssl_verify' => getenv('OPENROUTER_SSL_VERIFY') !== false && getenv('OPENROUTER_SSL_VERIFY') !== ''
            ? (string)getenv('OPENROUTER_SSL_VERIFY')
            : '0',
        'max_history' => (int)(getenv('OPENROUTER_MAX_HISTORY') ?: 10),
        'image_enabled' => false,
        'image_model' => 'google/gemini-2.5-flash-image',
        'image_max_size' => '1024x1024',
    ],
];
