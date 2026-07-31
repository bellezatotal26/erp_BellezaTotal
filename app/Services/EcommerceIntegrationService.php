<?php

namespace SoftNova\Services;

/**
 * Integraciones e-commerce (WooCommerce / Shopify / Mercado Libre).
 * Guarda credenciales y permite importar pedidos como ventas locales.
 */
class EcommerceIntegrationService
{
    public const PROVIDERS = [
        'woocommerce' => 'WooCommerce',
        'shopify' => 'Shopify',
        'mercadolibre' => 'Mercado Libre',
    ];

    public function __construct(private \PDO $db)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ecommerce_settings (
                provider VARCHAR(30) NOT NULL,
                setting_key VARCHAR(80) NOT NULL,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (provider, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function getConfig(string $provider): array
    {
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM ecommerce_settings WHERE provider = ?");
        $stmt->execute([$provider]);
        $out = ['enabled' => '0', 'store_url' => '', 'api_key' => '', 'api_secret' => '', 'webhook_token' => ''];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    public function saveConfig(string $provider, array $data): void
    {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new \InvalidArgumentException('Proveedor no soportado');
        }
        $keys = ['enabled', 'store_url', 'api_key', 'api_secret', 'webhook_token'];
        $stmt = $this->db->prepare(
            "INSERT INTO ecommerce_settings (provider, setting_key, setting_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $stmt->execute([$provider, $key, (string)$data[$key]]);
        }
    }

    /**
     * Importa un pedido externo como venta local (pago recibido).
     * @param array{external_id:string,customer_name?:string,total:float,items:list<array{name:string,qty:int,price:float}>,payment_method?:string} $order
     */
    public function importOrder(string $provider, array $order): int
    {
        $externalId = trim((string)($order['external_id'] ?? ''));
        if ($externalId === '') {
            throw new \InvalidArgumentException('ID externo requerido');
        }
        $exists = $this->db->prepare(
            "SELECT id FROM sales WHERE external_provider = ? AND external_id = ? LIMIT 1"
        );
        $exists->execute([$provider, $externalId]);
        if ($exists->fetchColumn()) {
            throw new \RuntimeException('El pedido ya fue importado');
        }

        $items = $order['items'] ?? [];
        if (empty($items)) {
            // Ítem genérico si solo viene total
            $items = [['name' => 'Pedido ' . $provider . ' ' . $externalId, 'qty' => 1, 'price' => (float)$order['total']]];
        }

        $subtotal = 0.0;
        $normalized = [];
        foreach ($items as $it) {
            $qty = max(1, (int)($it['qty'] ?? 1));
            $price = round((float)($it['price'] ?? 0), 2);
            $name = trim((string)($it['name'] ?? 'Producto'));
            $line = round($qty * $price, 2);
            $subtotal += $line;
            $normalized[] = compact('qty', 'price', 'name', 'line');
        }
        $total = round((float)($order['total'] ?? $subtotal), 2);
        if ($total <= 0) {
            $total = $subtotal;
        }

        $invoice = strtoupper(substr($provider, 0, 3)) . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $externalId);
        $method = PaymentMethodCatalog::normalize((string)($order['payment_method'] ?? 'payment_link'));
        $notes = 'Importado desde ' . (self::PROVIDERS[$provider] ?? $provider)
            . (!empty($order['customer_name']) ? (' · ' . $order['customer_name']) : '');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO sales
                    (invoice_number, document_type, customer_id, user_id, sale_date, due_date,
                     subtotal, tax, discount, total, payment_method, payment_terms, payment_status,
                     notes, external_provider, external_id, external_status, status)
                 VALUES (?, 'invoice', NULL, ?, NOW(), CURDATE(), ?, 0, 0, ?, ?, 'cash', 'paid', ?, ?, ?, 'imported', 'completed')"
            );
            // Ensure document columns exist
            (new SalesDocumentService($this->db));
            (new \SoftNova\Services\Integrations\IntegrationManager($this->db))->ensureSaleExternalColumns();

            $stmt->execute([
                $invoice,
                $_SESSION['tenant_user_id'] ?? null,
                $subtotal,
                $total,
                $method,
                $notes,
                $provider,
                $externalId,
            ]);
            $saleId = (int)$this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                "INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal)
                 VALUES (?, NULL, ?, ?, ?, 0, ?)"
            );
            foreach ($normalized as $n) {
                $itemStmt->execute([$saleId, $n['name'], $n['qty'], $n['price'], $n['line']]);
            }
            $payStmt = $this->db->prepare(
                "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                 VALUES (?, ?, ?, 'Pago e-commerce', ?)"
            );
            $payStmt->execute([$saleId, $total, $method, $_SESSION['tenant_user_id'] ?? null]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        try {
            (new AccountingService($this->db))->postSaleCascade($saleId);
        } catch (\Throwable $e) {
            error_log('Ecommerce accounting: ' . $e->getMessage());
        }

        return $saleId;
    }

    public function statuses(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $code => $label) {
            $cfg = $this->getConfig($code);
            $out[$code] = [
                'label' => $label,
                'enabled' => ($cfg['enabled'] ?? '0') === '1',
                'configured' => trim((string)($cfg['store_url'] ?? '')) !== '' || trim((string)($cfg['api_key'] ?? '')) !== '',
                'config' => [
                    'store_url' => $cfg['store_url'] ?? '',
                    'api_key' => $cfg['api_key'] ?? '',
                    'webhook_token' => $cfg['webhook_token'] ?? '',
                    // no exponer secret completo
                    'api_secret_set' => trim((string)($cfg['api_secret'] ?? '')) !== '',
                ],
            ];
        }
        return $out;
    }
}
