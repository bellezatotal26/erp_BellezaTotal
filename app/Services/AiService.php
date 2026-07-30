<?php

namespace SoftNova\Services;

/**
 * Servicio de IA via OpenRouter (compatible OpenAI)
 * Modelo: NVIDIA Nemotron 3 Ultra (free)
 */
class AiService
{
    private \PDO $db;
    private array $config;
    private static array $historySchemaDone = [];
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->config = \SoftNova\Core\config('ai', []);
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
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 180) : 'Nueva conversación';
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
            $title = mb_substr(trim(preg_replace('/\s+/', ' ', $firstMessage)), 0, 60);
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
        $model = (string)($this->config['model'] ?? 'nvidia/nemotron-3-ultra-550b-a55b:free');
        
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
    
    private function httpPostJson(string $url, array $payload, array $headers, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($errno) {
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
        return mb_substr($message, 0, 160);
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
