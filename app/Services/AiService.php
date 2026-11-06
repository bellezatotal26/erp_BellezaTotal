<?php

namespace SoftNova\Services;

/**
 * Servicio de IA via OpenRouter (compatible OpenAI).
 * Modelos separados: chat, facturas (texto) y visión (escaneos).
 */
class AiService
{
    private \PDO $db;
    private array $config;
    private static array $historySchemaDone = [];
    /** Último error de extracción de factura (para mostrar en notas de importación). */
    private string $lastError = '';
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->config = \SoftNova\Core\config('ai', []);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    private function setLastError(string $message): void
    {
        $this->lastError = trim($message);
        if ($this->lastError !== '') {
            error_log('AiService: ' . $this->safeError($this->lastError));
        }
    }

    private function clip(string $text, int $max): string
    {
        $max = max(1, $max);
        if (\function_exists('mb_substr')) {
            return (string)mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }

    /** Modelos VL tipo Qwen/Nemotron: imagen sí, PDF nativo no (cuelga/timeout → HTTP 500 en hosting). */
    private function modelAcceptsNativePdf(string $model): bool
    {
        $m = strtolower($model);
        if ($m === '') {
            return false;
        }
        if (str_contains($m, 'qwen') || str_contains($m, 'nemotron') || str_contains($m, 'llava')) {
            return false;
        }
        // Gemini / GPT / Claude vía OpenRouter suelen aceptar application/pdf.
        return str_contains($m, 'gemini')
            || str_contains($m, 'gpt-4o')
            || str_contains($m, 'gpt-4.1')
            || str_contains($m, 'claude');
    }

    /**
     * OpenRouter retira slugs viejos (p. ej. gemini-2.0-flash → "not a valid model ID").
     * Remapea a IDs vigentes sin obligar a editar .env a mano.
     */
    private function normalizeModelId(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return '';
        }
        $aliases = [
            'google/gemini-2.0-flash' => 'google/gemini-2.5-flash',
            'google/gemini-2.0-flash-001' => 'google/gemini-2.5-flash',
            'google/gemini-2.0-flash-lite' => 'google/gemini-2.5-flash-lite',
            'google/gemini-flash-1.5' => 'google/gemini-2.5-flash',
            'google/gemini-flash-1.5-8b' => 'google/gemini-2.5-flash-lite',
            'google/gemini-pro-1.5' => 'google/gemini-2.5-pro',
            'google/gemini-pro-vision' => 'google/gemini-2.5-flash',
        ];
        $key = strtolower($model);
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }
        // Prefijos retirados: gemini-2.0-* → 2.5-flash multimodal.
        if (preg_match('#^google/gemini-2\.0-#i', $model)) {
            return 'google/gemini-2.5-flash';
        }
        return $model;
    }

    /** Fallback si el modelo configurado ya no existe en OpenRouter. */
    private function visionFallbackModels(string $preferred = ''): array
    {
        $list = [
            $preferred,
            'google/gemini-2.5-flash',
            'google/gemini-3.5-flash',
            'google/gemini-3-flash-preview',
        ];
        $out = [];
        $seen = [];
        foreach ($list as $m) {
            $m = $this->normalizeModelId((string)$m);
            if ($m === '' || isset($seen[$m])) {
                continue;
            }
            $seen[$m] = true;
            $out[] = $m;
        }
        return $out;
    }

    private function isInvalidModelError(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'not a valid model')
            || str_contains($m, 'no endpoints found')
            || str_contains($m, 'model not found')
            || str_contains($m, 'is not available')
            || (str_contains($m, '404') && str_contains($m, 'model'));
    }

    /** Modelo para enviar PDF crudo (si el de visión no soporta PDF). */
    private function resolvePdfVisionModel(): string
    {
        $vision = $this->resolveModel('vision');
        if ($this->modelAcceptsNativePdf($vision)) {
            return $vision;
        }
        $pdf = trim((string)($this->config['pdf_model'] ?? ''));
        if ($pdf === '') {
            $pdf = trim((string)(getenv('OPENROUTER_PDF_MODEL') ?: ''));
        }
        if ($pdf === '') {
            $pdf = trim((string)($this->config['invoice_model'] ?? ''));
        }
        $pdf = $this->normalizeModelId($pdf);
        if ($pdf === '' || !$this->modelAcceptsNativePdf($pdf)) {
            $pdf = 'google/gemini-2.5-flash';
        }
        return $pdf;
    }
    
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function ensureHistorySchema(): void
    {
        $key = spl_object_id($this->db);
        if (isset(self::$historySchemaDone[$key])) {
            return;
        }
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_conversations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                title VARCHAR(180) NOT NULL DEFAULT 'Nueva conversación',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ai_conv_user (user_id),
                INDEX idx_ai_conv_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT UNSIGNED NOT NULL,
                role ENUM('user','assistant') NOT NULL,
                content MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_msg_conv (conversation_id),
                CONSTRAINT fk_ai_msg_conv FOREIGN KEY (conversation_id)
                    REFERENCES ai_conversations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$historySchemaDone[$key] = true;
    }

    /** @return array<int,array{id:int,title:string,updated_at:string}> */
    public function conversations(?int $userId = null, int $limit = 50): array
    {
        $this->ensureHistorySchema();
        $limit = max(1, min(100, $limit));
        if ($userId !== null) {
            return $this->query(
                "SELECT id, title, updated_at FROM ai_conversations
                 WHERE user_id = ? ORDER BY updated_at DESC LIMIT {$limit}",
                [$userId]
            )->fetchAll();
        }
        return $this->query(
            "SELECT id, title, updated_at FROM ai_conversations
             ORDER BY updated_at DESC LIMIT {$limit}"
        )->fetchAll();
    }

    /** @return array<int,array{role:string,content:string,created_at:string}> */
    public function messages(int $conversationId, int $limit = 100): array
    {
        $this->ensureHistorySchema();
        $limit = max(1, min(200, $limit));
        return $this->query(
            "SELECT role, content, created_at FROM ai_messages
             WHERE conversation_id = ? ORDER BY id ASC LIMIT {$limit}",
            [$conversationId]
        )->fetchAll();
    }

    public function conversationOwnedBy(int $conversationId, ?int $userId): bool
    {
        $this->ensureHistorySchema();
        $row = $this->query(
            "SELECT user_id FROM ai_conversations WHERE id = ? LIMIT 1",
            [$conversationId]
        )->fetch();
        if (!$row) {
            return false;
        }
        if ($userId === null) {
            return true;
        }
        return $row['user_id'] === null || (int)$row['user_id'] === $userId;
    }

    public function createConversation(?int $userId, string $title = 'Nueva conversación'): int
    {
        $this->ensureHistorySchema();
        $title = trim($title) !== '' ? $this->clip(trim($title), 180) : 'Nueva conversación';
        $this->query(
            "INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)",
            [$userId, $title]
        );
        return (int)$this->db->lastInsertId();
    }

    public function saveMessage(int $conversationId, string $role, string $content): void
    {
        $this->ensureHistorySchema();
        $role = $role === 'assistant' ? 'assistant' : 'user';
        $this->query(
            "INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, ?, ?)",
            [$conversationId, $role, $content]
        );
        $this->query(
            "UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?",
            [$conversationId]
        );
    }

    public function renameConversationFromMessage(int $conversationId, string $firstMessage): void
    {
        $this->ensureHistorySchema();
        $row = $this->query(
            "SELECT title FROM ai_conversations WHERE id = ? LIMIT 1",
            [$conversationId]
        )->fetch();
        if ($row && $row['title'] === 'Nueva conversación') {
            $title = $this->clip(trim(preg_replace('/\s+/', ' ', $firstMessage) ?? $firstMessage), 60);
            if ($title !== '') {
                $this->query(
                    "UPDATE ai_conversations SET title = ? WHERE id = ?",
                    [$title, $conversationId]
                );
            }
        }
    }

    public function deleteConversation(int $conversationId): void
    {
        $this->ensureHistorySchema();
        $this->query("DELETE FROM ai_conversations WHERE id = ?", [$conversationId]);
    }
    
    public function isConfigured(): bool
    {
        $enabled = (bool)($this->config['enabled'] ?? false);
        $key = trim((string)($this->config['api_key'] ?? ''));
        return $enabled && $key !== '';
    }

    /** @return array{chat:string,invoice:string,vision:string} */
    public function modelsForDisplay(): array
    {
        return [
            'chat' => $this->resolveModel('chat'),
            'invoice' => $this->resolveModel('invoice'),
            'vision' => $this->resolveModel('vision'),
        ];
    }

    private function resolveModel(string $role): string
    {
        $default = $this->normalizeModelId(trim((string)($this->config['default_model'] ?? '')));
        if ($default === '') {
            $default = 'google/gemini-2.5-flash';
        }

        $chains = [
            'chat' => ['chat_model', 'model', 'default_model'],
            'invoice' => ['invoice_model', 'chat_model', 'model', 'default_model'],
            'vision' => ['vision_model', 'invoice_model', 'chat_model', 'model', 'default_model'],
        ];

        foreach ($chains[$role] ?? ['default_model'] as $key) {
            $value = $this->normalizeModelId(trim((string)($this->config[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }
    
    /**
     * Genera respuesta del asistente Seri
     */
    public function chat(string $userMessage, string $tenantName = 'Mi Empresa', string $userName = 'Usuario', array $history = []): string
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return 'Por favor, escribe un mensaje para que pueda ayudarte.';
        }
        
        if (!$this->isConfigured()) {
            if (!empty($this->config['fallback_local'])) {
                return $this->localFallback($userMessage);
            }
            return 'El asistente IA no esta configurado. Agrega OPENROUTER_API_KEY en el archivo .env';
        }
        
        try {
            $context = $this->buildBusinessContext($tenantName, $userName);
            $reply = $this->callOpenRouter($userMessage, $context, $history);
            if ($reply !== '') {
                return $reply;
            }
        } catch (\Throwable $e) {
            error_log('AiService OpenRouter error: ' . $e->getMessage());
            if (!empty($this->config['fallback_local'])) {
                return $this->localFallback($userMessage)
                    . "\n\n(Nota: respuesta local; OpenRouter no respondio: "
                    . $this->safeError($e->getMessage()) . ')';
            }
            return 'No pude contactar al modelo de IA en este momento. Intenta de nuevo.';
        }
        
        if (!empty($this->config['fallback_local'])) {
            return $this->localFallback($userMessage);
        }
        
        return 'No recibí una respuesta valida del modelo. Intenta de nuevo.';
    }
    
    /**
     * Contexto compacto del negocio para el system prompt
     */
    public function buildBusinessContext(string $tenantName, string $userName): array
    {
        $totalProducts = (int)$this->query("SELECT COUNT(*) as c FROM products WHERE status='active'")->fetch()['c'];
        $totalServices = (int)$this->query("SELECT COUNT(*) as c FROM products WHERE status='active' AND product_type='service'")->fetch()['c'];
        $totalCustomers = (int)$this->query("SELECT COUNT(*) as c FROM customers WHERE status='active'")->fetch()['c'];
        $totalSales = (int)$this->query("SELECT COUNT(*) as c FROM sales WHERE status='completed'")->fetch()['c'];
        $todaySales = (float)$this->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE(sale_date)=CURDATE() AND status='completed'")->fetch()['t'];
        $monthSales = (float)$this->query("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE()) AND status='completed'")->fetch()['t'];
        $lowStock = (int)$this->query("SELECT COUNT(*) as c FROM products WHERE stock<=min_stock AND status='active' AND product_type='product'")->fetch()['c'];
        $pendingPayments = (float)$this->query(
            "SELECT COALESCE(SUM(GREATEST(s.total - COALESCE(p.paid_amount, 0), 0)), 0) as t
             FROM sales s
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE s.payment_status IN ('pending','partial')
               AND s.status IN ('completed','pending')"
        )->fetch()['t'];
        
        $topProducts = $this->query(
            "SELECT p.name, SUM(si.quantity) as cnt, SUM(si.subtotal) as total
             FROM sale_items si
             JOIN products p ON si.product_id = p.id
             JOIN sales s ON si.sale_id = s.id
             WHERE s.status = 'completed'
             GROUP BY p.id
             ORDER BY cnt DESC LIMIT 5"
        )->fetchAll();
        
        $lowStockProducts = $this->query(
            "SELECT name, stock, min_stock FROM products
             WHERE stock <= min_stock AND status = 'active' AND product_type = 'product'
             ORDER BY (min_stock - stock) DESC LIMIT 5"
        )->fetchAll();
        
        $topCustomers = $this->query(
            "SELECT COALESCE(NULLIF(TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,''))), ''), c.name) as name,
                    COUNT(s.id) as cnt, COALESCE(SUM(s.total),0) as total
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             WHERE s.status = 'completed' AND s.customer_id IS NOT NULL
             GROUP BY c.id
             ORDER BY cnt DESC LIMIT 3"
        )->fetchAll();
        
        $currency = $this->query("SELECT setting_value FROM settings WHERE setting_key='currency'")->fetch();
        $currencyCode = $currency['setting_value'] ?? 'COP';
        
        return [
            'empresa' => $tenantName,
            'usuario' => $userName,
            'moneda' => $currencyCode,
            'modulos_activos' => $this->activeModules(),
            'productos_activos' => $totalProducts,
            'servicios_activos' => $totalServices,
            'clientes_activos' => $totalCustomers,
            'ventas_completadas' => $totalSales,
            'ventas_hoy' => $todaySales,
            'ventas_mes' => $monthSales,
            'productos_stock_bajo' => $lowStock,
            'pagos_pendientes' => $pendingPayments,
            'top_productos' => $topProducts,
            'stock_bajo_detalle' => $lowStockProducts,
            'top_clientes' => $topCustomers,
        ];
    }

    private function activeModules(): array
    {
        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return [];
        }

        try {
            $masterDb = \SoftNova\Core\Database::getInstance();
            $plan = $masterDb->query(
                "SELECT sp.modules
                 FROM tenants t
                 JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
                 WHERE t.id = ? LIMIT 1",
                [$tenantId]
            )->fetch();
            $modules = json_decode((string)($plan['modules'] ?? '[]'), true);
            return is_array($modules) ? array_values(array_filter($modules, 'is_string')) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    private function callOpenRouter(string $userMessage, array $context, array $history = []): string
    {
        $baseUrl = rtrim((string)($this->config['base_url'] ?? 'https://openrouter.ai/api/v1'), '/');
        $url = $baseUrl . '/chat/completions';
        $apiKey = (string)$this->config['api_key'];
        $model = $this->resolveModel('chat');
        
        $system = $this->systemPrompt($context);

        $messages = [['role' => 'system', 'content' => $system]];
        // Historial previo (limitado a los últimos turnos para no exceder el contexto).
        $maxHistory = (int)($this->config['max_history'] ?? 10);
        if ($maxHistory > 0 && $history) {
            $recent = array_slice($history, -$maxHistory);
            foreach ($recent as $h) {
                $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
                $content = trim((string)($h['content'] ?? ''));
                if ($content !== '') {
                    $messages[] = ['role' => $role, 'content' => $content];
                }
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float)($this->config['temperature'] ?? 0.4),
            'max_tokens' => (int)($this->config['max_tokens'] ?? 1200),
        ];
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: ' . ($this->config['site_url'] ?? 'http://localhost'),
            'X-Title: ' . ($this->config['site_name'] ?? 'Seri ERP'),
        ];
        
        $response = $this->httpPostJson($url, $payload, $headers, (int)($this->config['timeout'] ?? 60));
        
        if (!is_array($response)) {
            throw new \RuntimeException('Respuesta vacia de OpenRouter');
        }
        
        if (isset($response['error'])) {
            $msg = is_array($response['error'])
                ? ($response['error']['message'] ?? json_encode($response['error']))
                : (string)$response['error'];
            throw new \RuntimeException($msg);
        }
        
        $content = $response['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            // Algunos modelos devuelven content como lista de partes
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $parts[] = (string)$part['text'];
                }
            }
            $content = implode("\n", $parts);
        }
        
        return trim((string)$content);
    }
    
    private function systemPrompt(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $modules = is_array($context['modulos_activos'] ?? null) ? $context['modulos_activos'] : [];
        $p = \SoftNova\Core\ai_personality($modules);
        
        $name = (string)($p['name'] ?? 'Seri');
        $tone = (string)($p['tone'] ?? 'clara, profesional y concisa');
        $language = (string)($p['language'] ?? 'español');
        $extra = trim((string)($p['extra_instructions'] ?? ''));
        
        $rules = $p['rules'] ?? [];
        if (!is_array($rules)) {
            $rules = [];
        }
        $rulesText = '';
        foreach ($rules as $rule) {
            $rulesText .= '- ' . trim((string)$rule) . "\n";
        }
        
        $skills = $p['skills'] ?? [];
        $skillsText = '';
        if (is_array($skills) && !empty($skills)) {
            $skillsText = "Skills / especialidades:\n";
            foreach ($skills as $key => $desc) {
                $skillsText .= '- ' . $key . ': ' . trim((string)$desc) . "\n";
            }
        }
        
        return "Eres {$name}, asistente virtual del ERP Seri ERP.\n"
            . "Respondes en {$language}, con tono {$tone}.\n"
            . ($extra !== '' ? $extra . "\n" : '')
            . ($rulesText !== '' ? "Reglas:\n{$rulesText}" : '')
            . ($skillsText !== '' ? "\n{$skillsText}" : '')
            . "\nContexto del negocio (JSON):\n" . $json;
    }
    
    /**
     * Extrae la cabecera y las líneas de una factura de compra en texto plano.
     * Devuelve null si la IA no está configurada o la respuesta no es utilizable,
     * para que el flujo continúe con el análisis heurístico.
     *
     * @return array{invoice_number?:string,invoice_date?:string,due_date?:string,supplier_name?:string,subtotal?:float,tax_total?:float,total?:float,items:list<array<string,mixed>>}|null
     */
    public function extractInvoiceData(string $rawText): ?array
    {
        $this->lastError = '';
        $rawText = trim($rawText);
        if ($rawText === '' || !$this->isConfigured()) {
            if (!$this->isConfigured()) {
                $this->setLastError('IA no configurada (OPENROUTER_API_KEY)');
            }
            return null;
        }
        // Recorta para no exceder el contexto: las facturas útiles caben de sobra.
        $rawText = $this->clip($rawText, 12000);

        $model = $this->resolveModel('invoice');
        try {
            $baseUrl = rtrim((string)($this->config['base_url'] ?? 'https://openrouter.ai/api/v1'), '/');
            $payload = $this->invoiceCompletionPayload(
                $model,
                [
                    ['role' => 'system', 'content' => $this->invoiceTextPrompt()],
                    ['role' => 'user', 'content' => $rawText],
                ]
            );
            $response = $this->httpPostJson(
                $baseUrl . '/chat/completions',
                $payload,
                $this->openRouterHeaders(),
                (int)($this->config['invoice_timeout'] ?? $this->config['timeout'] ?? 90)
            );
        } catch (\Throwable $e) {
            $this->setLastError('Texto/IA [' . $model . ']: ' . $e->getMessage());
            return null;
        }

        if (isset($response['error'])) {
            $this->setLastError('OpenRouter texto [' . $model . ']: ' . $this->formatApiError($response['error']));
            return null;
        }

        $decoded = $this->decodeVisionContent($response);
        if ($decoded === null) {
            $this->setLastError('La IA texto [' . $model . '] no devolvió JSON utilizable');
        }
        return $decoded;
    }

    /**
     * Extrae factura desde imágenes (foto o páginas de PDF escaneado) vía modelo con visión.
     *
     * @param list<array{mime:string,data:string}> $images
     * @return array{invoice_number?:string,invoice_date?:string,due_date?:string,supplier_name?:string,subtotal?:float,tax_total?:float,total?:float,items:list<array<string,mixed>>}|null
     */
    public function extractInvoiceFromImages(array $images): ?array
    {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->setLastError('IA no configurada (OPENROUTER_API_KEY)');
            return null;
        }
        if ($images === []) {
            $this->setLastError('Sin imágenes para enviar a la IA');
            return null;
        }

        if (\function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }

        $parts = [['type' => 'text', 'text' => $this->invoiceVisionPrompt()]];
        $skipped = 0;

        foreach (array_slice($images, 0, 3) as $idx => $img) {
            $data = (string)($img['data'] ?? '');
            $mime = (string)($img['mime'] ?? 'image/jpeg');
            if ($data === '') {
                $skipped++;
                continue;
            }
            // Detectar mime real por firma (algunos uploads llegan como octet-stream).
            if (!str_starts_with($mime, 'image/')) {
                $mime = $this->detectImageMime($data) ?? $mime;
            }
            if (!str_starts_with($mime, 'image/')) {
                $skipped++;
                continue;
            }

            // Capturas PNG/Opera suelen ser enormes: siempre compactar a JPEG.
            $prepared = $this->prepareVisionImageBinary($data, $mime);
            if ($prepared === null) {
                $skipped++;
                continue;
            }
            $data = $prepared['data'];
            $mime = $prepared['mime'];

            $parts[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . base64_encode($data),
                ],
            ];
        }

        if (count($parts) < 2) {
            $this->setLastError(
                'Las imágenes eran demasiado grandes, corruptas o el servidor no pudo comprimirlas'
                . ($skipped > 0 ? (' (' . $skipped . ' omitida(s))') : '')
                . '. Pruebe JPG más liviano o PDF'
            );
            return null;
        }

        return $this->callVisionCompletion($parts, 'visión/imagen');
    }

    private function detectImageMime(string $binary): ?string
    {
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($binary, 'GIF87a') || str_starts_with($binary, 'GIF89a')) {
            return 'image/gif';
        }
        return null;
    }

    /**
     * Compacta a JPEG ≤ ~1.2 MB para OpenRouter (capturas de pantalla suelen fallar en crudo).
     *
     * @return array{data:string,mime:string}|null
     */
    private function prepareVisionImageBinary(string $data, string $mime): ?array
    {
        $maxBytes = 1_200_000;
        // Ya es JPEG pequeño: listo.
        if ($mime === 'image/jpeg' && strlen($data) <= $maxBytes) {
            return ['data' => $data, 'mime' => 'image/jpeg'];
        }

        foreach ([1600, 1280, 1024, 800] as $maxEdge) {
            foreach ([82, 72, 62] as $quality) {
                $scaled = $this->downscaleImageBinary($data, $mime, $maxEdge, $quality);
                if ($scaled !== null && $scaled !== '' && strlen($scaled) <= $maxBytes) {
                    return ['data' => $scaled, 'mime' => 'image/jpeg'];
                }
                if ($scaled !== null && $scaled !== '' && $maxEdge <= 800 && $quality <= 62) {
                    // Último recurso: aceptar hasta 2 MB.
                    if (strlen($scaled) <= 2_000_000) {
                        return ['data' => $scaled, 'mime' => 'image/jpeg'];
                    }
                }
            }
        }

        // Sin GD: enviar original si cabe.
        if (strlen($data) <= $maxBytes && str_starts_with($mime, 'image/')) {
            return ['data' => $data, 'mime' => $mime];
        }
        if (strlen($data) <= 2_500_000 && str_starts_with($mime, 'image/')) {
            return ['data' => $data, 'mime' => $mime];
        }
        return null;
    }

    /**
     * Envía el PDF completo al modelo (Gemini vía OpenRouter acepta application/pdf).
     * Útil cuando el servidor no tiene Imagick/Ghostscript para rasterizar páginas.
     *
     * @return array{invoice_number?:string,invoice_date?:string,due_date?:string,supplier_name?:string,subtotal?:float,tax_total?:float,total?:float,items:list<array<string,mixed>>}|null
     */
    public function extractInvoiceFromPdf(string $pdfBinary, string $filename = 'factura.pdf'): ?array
    {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->setLastError('IA no configurada (OPENROUTER_API_KEY)');
            return null;
        }
        $pdfBinary = (string)$pdfBinary;
        if ($pdfBinary === '' || strlen($pdfBinary) < 100) {
            $this->setLastError('PDF vacío o ilegible');
            return null;
        }
        // Hostings con poca RAM: base64+JSON duplican memoria. Tope conservador ~2.5 MB.
        if (strlen($pdfBinary) > 2_500_000) {
            $this->setLastError(
                'PDF demasiado grande para IA en este servidor ('
                . round(strlen($pdfBinary) / 1048576, 1)
                . ' MB). Suba foto JPG/PNG de las páginas o un CSV/Excel'
            );
            return null;
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'factura.pdf';
        if (strtolower(substr($filename, -4)) !== '.pdf') {
            $filename .= '.pdf';
        }

        $pdfModel = $this->resolvePdfVisionModel();
        $visionModel = $this->resolveModel('vision');
        // Evitar doble llamada (file + url) con Qwen: suele tumbar el PHP del hosting (timeout → HTTP 500).
        if (!$this->modelAcceptsNativePdf($visionModel) && $pdfModel === $visionModel) {
            $this->setLastError(
                'El modelo de visión [' . $visionModel . '] no lee PDF nativo en este servidor. '
                . 'Suba foto JPG/PNG, o configure OPENROUTER_PDF_MODEL=google/gemini-2.5-flash'
            );
            return null;
        }

        try {
            if (\function_exists('ini_set')) {
                @ini_set('memory_limit', '256M');
            }
            $dataUrl = 'data:application/pdf;base64,' . base64_encode($pdfBinary);
        } catch (\Throwable $e) {
            $this->setLastError('Memoria insuficiente al preparar el PDF para IA. Use foto JPG/PNG');
            return null;
        }

        // Una sola llamada (file). El doble intento file+url duplicaba el tiempo.
        $parts = [
            ['type' => 'text', 'text' => $this->invoiceVisionPrompt()],
            [
                'type' => 'file',
                'file' => [
                    'filename' => $filename,
                    'file_data' => $dataUrl,
                ],
            ],
        ];
        return $this->callVisionCompletion($parts, 'visión/PDF', $pdfModel);
    }

    /** Prompt corto (como al inicio del desarrollo): prioridad = que cargue líneas, no máxima precisión. */
    private function invoiceJsonSchemaHint(): string
    {
        return <<<'PROMPT'
Devuelve EXCLUSIVAMENTE un objeto JSON válido, sin markdown ni texto extra.
{
  "invoice_number": "",
  "invoice_date": "YYYY-MM-DD o vacio",
  "due_date": "YYYY-MM-DD o vacio",
  "supplier_name": "",
  "subtotal": 0,
  "tax_total": 0,
  "total": 0,
  "items": [
    {
      "supplier_sku": "",
      "barcode": "",
      "description": "",
      "quantity": 0,
      "unit_cost": 0,
      "tax_rate": 0,
      "discount_pct": 0.00,
      "line_total": 0,
      "lot_code": "",
      "expiry_date": ""
    }
  ]
}
Reglas: punto decimal; quantity=unidades (no ml/gr); separa SKU e EAN si ambos aparecen; incluye todas las líneas de producto visibles; omite flete/legales.
PROMPT;
    }

    private function invoiceTextPrompt(): string
    {
        return "Extractor de facturas de compra (Colombia).\n"
            . $this->invoiceJsonSchemaHint();
    }

    private function invoiceVisionPrompt(): string
    {
        return "Lee la factura de compra (foto o PDF escaneado, Colombia) y extrae cabecera + TODAS las líneas de producto.\n"
            . "Columnas típicas: CODIGO/SKU, DESCRIPCION, LOTE, CANT, VR UNIT, DTO%, IVA, VLR TOTAL.\n"
            . "quantity = cantidad de unidades (no ml/g del empaque). unit_cost = valor unitario neto si se ve; si solo hay total de línea, deriva unit_cost = line_total/quantity.\n"
            . "discount_pct = DTO% EXACTO con 2 decimales (14.30 → 14.30, NUNCA redondear a 14). tax_rate = IVA % (19, 5 o 0). lot_code = LOTE si aparece.\n"
            . $this->invoiceJsonSchemaHint();
    }

    /** @return array<string,string> */
    private function openRouterHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string)$this->config['api_key'],
            'HTTP-Referer: ' . ($this->config['site_url'] ?? 'http://localhost'),
            'X-Title: ' . ($this->config['site_name'] ?? 'Seri ERP'),
        ];
    }

    /**
     * Payload compatible con Gemini 2.5 (razonamiento) y modelos de visión clásicos.
     *
     * @param list<array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    private function invoiceCompletionPayload(string $model, array $messages): array
    {
        // 4000 basta para facturas típicas; 8000+ + cascadas = lento e ineficiente.
        $maxTokens = (int)($this->config['invoice_max_tokens'] ?? 4000);
        if ($maxTokens < 2000) {
            $maxTokens = 2000;
        }
        if ($maxTokens > 6000) {
            $maxTokens = 6000;
        }
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => $maxTokens,
        ];
        // Sin “thinking”: más rápido y el JSON no se come los tokens.
        if (
            stripos($model, 'gemini') !== false
            || stripos($model, 'gpt-5') !== false
            || stripos($model, 'nemotron-3') !== false
        ) {
            $payload['reasoning'] = ['exclude' => true];
        }
        return $payload;
    }

    /**
     * @param list<array<string,mixed>> $parts
     * @return array{invoice_number?:string,invoice_date?:string,due_date?:string,supplier_name?:string,subtotal?:float,tax_total?:float,total?:float,items:list<array<string,mixed>>}|null
     */
    private function callVisionCompletion(array $parts, string $label, ?string $modelOverride = null): ?array
    {
        $preferred = $this->normalizeModelId(trim((string)($modelOverride ?? '')) ?: $this->resolveModel('vision'));
        $timeout = (int)($this->config['vision_timeout'] ?? $this->config['timeout'] ?? 60);
        if ($timeout < 25) {
            $timeout = 25;
        }
        if ($timeout > 75) {
            $timeout = 75;
        }

        $baseUrl = rtrim((string)($this->config['base_url'] ?? 'https://openrouter.ai/api/v1'), '/');
        $lastErr = '';
        // Preferido + 1–2 fallbacks SOLO si el ID es inválido/retirado (no por JSON vacío).
        foreach ($this->visionFallbackModels($preferred) as $i => $model) {
            try {
                $response = $this->httpPostJson(
                    $baseUrl . '/chat/completions',
                    $this->invoiceCompletionPayload($model, [
                        ['role' => 'user', 'content' => $parts],
                    ]),
                    $this->openRouterHeaders(),
                    $timeout
                );
            } catch (\Throwable $e) {
                $lastErr = $label . ' [' . $model . ']: ' . $e->getMessage();
                if ($this->isInvalidModelError($lastErr) && $i < 2) {
                    continue;
                }
                $this->setLastError($lastErr);
                return null;
            }

            if (isset($response['error'])) {
                $lastErr = 'OpenRouter ' . $label . ' [' . $model . ']: ' . $this->formatApiError($response['error']);
                if ($this->isInvalidModelError($lastErr) && $i < 2) {
                    continue;
                }
                $this->setLastError($lastErr);
                return null;
            }

            $decoded = $this->decodeVisionContent($response);
            if ($decoded === null) {
                $finish = (string)($response['choices'][0]['finish_reason'] ?? '');
                $snip = $this->responseTextSnippet($response);
                $this->setLastError(
                    'La IA ' . $label . ' [' . $model . '] no devolvió JSON utilizable'
                    . ($finish !== '' ? (' (finish=' . $finish . ')') : '')
                    . ($snip !== '' ? (': ' . $snip) : '')
                );
                return null;
            }
            if (empty($decoded['items'])) {
                $this->setLastError('La IA ' . $label . ' [' . $model . '] respondió sin líneas de producto');
            }
            return $decoded;
        }

        $this->setLastError($lastErr !== '' ? $lastErr : ('OpenRouter ' . $label . ': sin modelo usable'));
        return null;
    }

    /** @param array<string,mixed> $response */
    private function decodeVisionContent(array $response): ?array
    {
        $message = $response['choices'][0]['message'] ?? [];
        if (!is_array($message)) {
            $message = [];
        }

        $chunks = [];
        $content = $message['content'] ?? '';
        if (is_string($content) && trim($content) !== '') {
            $chunks[] = $content;
        } elseif (is_array($content)) {
            foreach ($content as $part) {
                if (is_string($part) && trim($part) !== '') {
                    $chunks[] = $part;
                } elseif (is_array($part)) {
                    $type = (string)($part['type'] ?? '');
                    if ($type === 'text' || $type === 'output_text') {
                        $chunks[] = (string)($part['text'] ?? '');
                    } elseif (isset($part['text'])) {
                        $chunks[] = (string)$part['text'];
                    }
                }
            }
        }
        // Algunos modelos (Gemini/OpenRouter) ponen texto útil en otros campos.
        foreach (['reasoning', 'reasoning_content', 'refusal'] as $extraKey) {
            if (!empty($message[$extraKey]) && is_string($message[$extraKey])) {
                $chunks[] = $message[$extraKey];
            }
        }

        $text = trim(implode("\n", array_filter($chunks, static fn($c) => trim((string)$c) !== '')));
        if ($text === '') {
            return null;
        }
        return $this->decodeJsonPayload($text);
    }

    /** @param array<string,mixed> $response */
    private function responseTextSnippet(array $response): string
    {
        $message = $response['choices'][0]['message'] ?? [];
        $content = is_array($message) ? ($message['content'] ?? '') : '';
        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
        }
        $content = trim((string)$content);
        if ($content === '') {
            return '';
        }
        $flat = preg_replace('/\s+/', ' ', $content) ?? $content;
        return \function_exists('mb_substr') ? mb_substr($flat, 0, 120) : substr($flat, 0, 120);
    }

    /** @param mixed $error */
    private function formatApiError($error): string
    {
        if (is_string($error)) {
            return $error;
        }
        if (!is_array($error)) {
            return 'error desconocido';
        }
        $msg = (string)($error['message'] ?? $error['code'] ?? '');
        $extra = [];
        if (isset($error['metadata']) && is_array($error['metadata'])) {
            $meta = $error['metadata'];
            foreach (['raw', 'provider_name', 'provider_error'] as $k) {
                if (!empty($meta[$k]) && is_string($meta[$k])) {
                    $extra[] = $meta[$k];
                } elseif (!empty($meta[$k]) && is_array($meta[$k])) {
                    $extra[] = (string)json_encode($meta[$k]);
                }
            }
        }
        if ($extra !== []) {
            $joined = implode(' | ', $extra);
            if ($msg === '' || stripos($joined, $msg) === false) {
                $msg = trim($msg . ($msg !== '' ? ' — ' : '') . $joined);
            }
        }
        if ($msg === '') {
            $msg = (string)json_encode($error);
        }
        return $this->clip($this->safeError($msg), 280);
    }

    private function downscaleImageBinary(string $data, string $mime, int $maxEdge = 1600, int $quality = 82): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);
            return null;
        }
        $maxEdge = max(400, $maxEdge);
        $quality = max(40, min(90, $quality));
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($img);
            return null;
        }
        // PNG con transparencia → fondo blanco (JPEG no tiene alpha).
        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, $quality);
        $out = ob_get_clean();
        imagedestroy($dst);
        imagedestroy($img);
        return is_string($out) && $out !== '' ? $out : null;
    }

    /**
     * Aísla el objeto JSON de una respuesta que puede venir con texto alrededor.
     *
     * @return array<string,mixed>|null
     */
    private function decodeJsonPayload(string $content): ?array
    {
        $content = trim($content);
        // Quitar fences markdown y basura típica de modelos con razonamiento.
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);

        $candidates = [];
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($content, $start, $end - $start + 1);
        }
        // A veces el modelo envuelve el objeto en un array [{...}].
        $aStart = strpos($content, '[');
        $aEnd = strrpos($content, ']');
        if ($aStart !== false && $aEnd !== false && $aEnd > $aStart) {
            $candidates[] = substr($content, $aStart, $aEnd - $aStart + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }
            // Array de un solo objeto factura.
            if (isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['items'])) {
                $decoded = $decoded[0];
            }
            if (!isset($decoded['items']) || !is_array($decoded['items'])) {
                // Objeto con clave anidada típica.
                foreach (['data', 'invoice', 'result', 'factura'] as $wrap) {
                    if (isset($decoded[$wrap]) && is_array($decoded[$wrap]) && isset($decoded[$wrap]['items'])) {
                        $decoded = $decoded[$wrap];
                        break;
                    }
                }
            }
            if (!isset($decoded['items']) || !is_array($decoded['items'])) {
                $decoded['items'] = [];
            }
            return $decoded;
        }

        return null;
    }

    /**
     * Hostings compartidos suelen no tener CA bundle → cURL SSL falla y la IA “no hace nada”.
     * Preferimos storage/certs/cacert.pem; si falla el SSL, reintento sin verificar (solo OpenRouter).
     */
    private function curlSslOptions(bool $verify): array
    {
        if (!$verify) {
            return [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        $opts = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $candidates = [];
        if (defined('ROOT_PATH')) {
            $candidates[] = ROOT_PATH . '/storage/certs/cacert.pem';
        }
        $candidates[] = dirname(__DIR__, 2) . '/storage/certs/cacert.pem';
        $iniCa = (string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        if ($iniCa !== '') {
            $candidates[] = $iniCa;
        }
        foreach ($candidates as $ca) {
            if (is_string($ca) && $ca !== '' && is_readable($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
                break;
            }
        }
        return $opts;
    }

    private function openRouterSslVerifyEnabled(): bool
    {
        // Default 0: en este hosting el CA está roto (error “unable to get local issuer certificate”).
        $flag = strtolower(trim((string)(getenv('OPENROUTER_SSL_VERIFY') ?: ($this->config['ssl_verify'] ?? '0'))));
        return !in_array($flag, ['0', 'false', 'off', 'no', ''], true);
    }

    private function isCurlSslError(int $errno, string $error): bool
    {
        // 60 CURLE_SSL_CACERT, 77 CURLE_SSL_CACERT_BADFILE, 51 CURLE_PEER_FAILED_VERIFICATION
        if (in_array($errno, [51, 60, 77], true)) {
            return true;
        }
        return stripos($error, 'SSL') !== false
            || stripos($error, 'certificate') !== false
            || stripos($error, 'issuer') !== false;
    }

    private function httpPostJson(string $url, array $payload, array $headers, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('No se pudo armar la petición IA (archivo muy grande o memoria baja)');
        }

        if (function_exists('curl_init')) {
            $verify = $this->openRouterSslVerifyEnabled();
            $attempt = 0;
            $raw = false;
            $errno = 0;
            $error = '';
            $status = 0;

            while ($attempt < 2) {
                $attempt++;
                $ch = curl_init($url);
                $opts = [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 15,
                ] + $this->curlSslOptions($verify);
                curl_setopt_array($ch, $opts);
                $raw = curl_exec($ch);
                $errno = curl_errno($ch);
                $error = (string)curl_error($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($errno === 0) {
                    break;
                }
                // Certificados rotos del hosting: un reintento sin verify (misma práctica que muchos ERPs en cPanel).
                if ($verify && $this->isCurlSslError($errno, $error) && $attempt === 1) {
                    error_log('AiService OpenRouter SSL falló (' . $error . '); reintento sin verificación de certificado');
                    $verify = false;
                    continue;
                }
                break;
            }

            if ($errno === 28) {
                throw new \RuntimeException(
                    'Tiempo agotado esperando a OpenRouter (' . $timeout . 's). '
                    . 'Pruebe foto JPG/PNG o un PDF más liviano'
                );
            }
            if ($errno) {
                if ($this->isCurlSslError($errno, $error)) {
                    throw new \RuntimeException(
                        'SSL del hosting bloquea OpenRouter. Suba storage/certs/cacert.pem '
                        . 'o ponga OPENROUTER_SSL_VERIFY=0 en .env'
                    );
                }
                throw new \RuntimeException('cURL: ' . $error);
            }
            if ($raw === false || $raw === '') {
                throw new \RuntimeException('Sin respuesta HTTP (status ' . $status . ')');
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('JSON invalido de OpenRouter (HTTP ' . $status . ')');
            }

            if ($status >= 400 && !isset($decoded['error'])) {
                $decoded['error'] = ['message' => 'HTTP ' . $status];
            }

            return $decoded;
        }
        
        $headerLine = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerLine,
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('No se pudo conectar a OpenRouter');
        }
        
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON invalido de OpenRouter');
        }
        
        return $decoded;
    }
    
    private function safeError(string $message): string
    {
        $message = preg_replace('/sk-or-v1-[A-Za-z0-9_-]+/', '[API_KEY]', $message) ?? $message;
        return $this->clip($message, 160);
    }
    
    /**
     * Fallback local (reglas) si no hay API key o falla la red
     */
    private function localFallback(string $message): string
    {
        $ctx = $this->buildBusinessContext(
            $_SESSION['tenant_name'] ?? 'Mi Empresa',
            $_SESSION['tenant_user_name'] ?? 'Usuario'
        );
        
        $msg = strtolower($message);
        $today = number_format((float)$ctx['ventas_hoy'], 0, ',', '.');
        $month = number_format((float)$ctx['ventas_mes'], 0, ',', '.');
        
        if (preg_match('/hola|buenos|saludos|hey|buenas/i', $msg)) {
            return "Hola. Soy Seri. Tienes {$ctx['productos_activos']} productos, {$ctx['clientes_activos']} clientes y ventas hoy por {$ctx['moneda']} {$today}. En que te ayudo?";
        }
        
        if (preg_match('/stock|inventario|existencia|reabastecer|faltante/i', $msg)) {
            $lines = ["Inventario: {$ctx['productos_activos']} productos activos, {$ctx['productos_stock_bajo']} con stock bajo."];
            foreach ($ctx['stock_bajo_detalle'] as $p) {
                $lines[] = "- {$p['name']}: stock {$p['stock']} (min {$p['min_stock']})";
            }
            return implode("\n", $lines);
        }
        
        if (preg_match('/venta|vendido|factur|ingreso|ganancia/i', $msg)) {
            return "Ventas hoy: {$ctx['moneda']} {$today}\nVentas del mes: {$ctx['moneda']} {$month}\nVentas completadas: {$ctx['ventas_completadas']}\nPagos pendientes: {$ctx['moneda']} " . number_format((float)$ctx['pagos_pendientes'], 0, ',', '.');
        }
        
        if (preg_match('/cliente/i', $msg)) {
            $lines = ["Clientes activos: {$ctx['clientes_activos']}"];
            foreach ($ctx['top_clientes'] as $c) {
                $lines[] = "- {$c['name']}: {$c['cnt']} compras";
            }
            return implode("\n", $lines);
        }
        
        return "Modo local activo (sin OpenRouter). Preguntame por ventas, inventario o clientes. Para IA completa configura OPENROUTER_API_KEY en .env";
    }
}
