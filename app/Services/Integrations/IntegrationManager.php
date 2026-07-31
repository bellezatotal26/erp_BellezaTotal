<?php

namespace SoftNova\Services\Integrations;

/**
 * Orquestador multi-proveedor de facturación electrónica.
 */
class IntegrationManager
{
    private IntegrationSettingsService $settings;
    private \PDO $db;

    /** @var array<string, InvoiceProviderInterface> */
    private array $providers = [];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->settings = new IntegrationSettingsService($db);
        $active = $this->settings->getActiveProvider();

        $this->providers = [
            'alegra' => new AlegraConnector($this->settings->providerConfig('alegra'), $active === 'alegra', $this->settings),
            'siigo' => new SiigoConnector($this->settings->providerConfig('siigo'), $active === 'siigo', $this->settings),
            'factus' => new FactusConnector($this->settings->providerConfig('factus'), $active === 'factus', $this->settings),
            'dian' => new DianConnector($this->settings->providerConfig('dian'), $active === 'dian'),
        ];
    }

    public function ensureSaleExternalColumns(): void
    {
        static $done = [];
        $oid = spl_object_id($this->db);
        if (isset($done[$oid])) {
            return;
        }
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM sales LIKE 'external_provider'")->fetch();
            if (!$cols) {
                $this->db->exec(
                    "ALTER TABLE sales
                     ADD COLUMN external_provider VARCHAR(30) NULL AFTER notes,
                     ADD COLUMN external_id VARCHAR(80) NULL AFTER external_provider,
                     ADD COLUMN external_status VARCHAR(40) NULL AFTER external_id,
                     ADD COLUMN external_error TEXT NULL AFTER external_status"
                );
            }
        } catch (\Throwable $e) {
            // silencioso
        }
        $done[$oid] = true;
    }

    /**
     * Emite factura electrónica de una venta local vía el proveedor activo.
     *
     * @return array{success:bool,message:string,provider?:string,external_id?:string}
     */
    public function emitSale(int $saleId): array
    {
        $provider = $this->active();
        if (!$provider) {
            return ['success' => false, 'message' => 'No hay proveedor de facturación activo'];
        }

        $this->ensureSaleExternalColumns();
        $pdo = $this->db;

        $stmt = $pdo->prepare(
            "SELECT s.*, c.id AS customer_local_id, c.name AS customer_name, c.first_name, c.last_name,
                    c.company_name, c.document_type, c.document_number, c.email AS customer_email,
                    c.phone AS customer_phone, c.address AS customer_address, c.city AS customer_city
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return ['success' => false, 'message' => 'Venta no encontrada'];
        }

        if (!empty($sale['external_id']) && ($sale['external_provider'] ?? '') === $provider->code()) {
            return [
                'success' => true,
                'message' => 'Ya estaba emitida en ' . $provider->label(),
                'provider' => $provider->code(),
                'external_id' => (string)$sale['external_id'],
            ];
        }

        $itemsStmt = $pdo->prepare(
            "SELECT si.*, p.code, p.product_type, p.name AS product_catalog_name
             FROM sale_items si
             LEFT JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = ?"
        );
        $itemsStmt->execute([$saleId]);
        $items = $itemsStmt->fetchAll();
        foreach ($items as &$item) {
            if (empty($item['product_name'])) {
                $item['product_name'] = $item['product_catalog_name'] ?? 'Producto';
            }
        }
        unset($item);

        $sale['customer'] = [
            'id' => $sale['customer_local_id'] ?? $sale['customer_id'] ?? null,
            'name' => $sale['customer_name'] ?? 'Cliente general',
            'first_name' => $sale['first_name'] ?? null,
            'last_name' => $sale['last_name'] ?? null,
            'company_name' => $sale['company_name'] ?? null,
            'document_type' => $sale['document_type'] ?? 'CC',
            'document_number' => $sale['document_number'] ?? null,
            'email' => $sale['customer_email'] ?? null,
            'phone' => $sale['customer_phone'] ?? null,
            'address' => $sale['customer_address'] ?? null,
            'city' => $sale['customer_city'] ?? null,
        ];

        $paymentsStmt = $pdo->prepare("SELECT * FROM sale_payments WHERE sale_id = ?");
        $paymentsStmt->execute([$saleId]);
        $payments = $paymentsStmt->fetchAll();

        $result = $provider->pushSale($sale, $items, $payments);
        $result['provider'] = $provider->code();

        try {
            $upd = $pdo->prepare(
                "UPDATE sales
                 SET external_provider = ?, external_id = ?, external_status = ?, external_error = ?
                 WHERE id = ?"
            );
            $upd->execute([
                $provider->code(),
                $result['external_id'] ?? null,
                !empty($result['success']) ? 'synced' : 'error',
                !empty($result['success']) ? null : mb_substr((string)($result['message'] ?? 'Error'), 0, 1000),
                $saleId,
            ]);
        } catch (\Throwable $e) {
            // columnas pueden no existir aún
        }

        return $result;
    }

    public function settings(): IntegrationSettingsService
    {
        return $this->settings;
    }

    /** @return array<string, InvoiceProviderInterface> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function provider(string $code): ?InvoiceProviderInterface
    {
        return $this->providers[$code] ?? null;
    }

    public function active(): ?InvoiceProviderInterface
    {
        $code = $this->settings->getActiveProvider();
        return $code ? ($this->providers[$code] ?? null) : null;
    }

    /** @return array<string, array> */
    public function statuses(): array
    {
        $out = [];
        foreach ($this->providers as $code => $provider) {
            $out[$code] = $provider->status() + [
                'label' => $provider->label(),
                'form' => $this->settings->all($code, true),
            ];
        }
        return $out;
    }

    public function saveProvider(string $provider, array $input, bool $setActive = false): void
    {
        if (!isset($this->providers[$provider])) {
            throw new \InvalidArgumentException('Proveedor desconocido');
        }

        $secretKeys = match ($provider) {
            'alegra' => ['token' => true],
            'siigo' => ['access_key' => true],
            'factus' => ['client_secret' => true, 'password' => true],
            'dian' => ['technical_key' => true, 'pin' => true],
            default => [],
        };

        $fields = match ($provider) {
            'alegra' => [
                'enabled' => !empty($input['enabled']) ? '1' : '0',
                'email' => $input['email'] ?? '',
                'token' => $input['token'] ?? '',
                'base_url' => $input['base_url'] ?? 'https://api.alegra.com/api/v1',
                'tax_id' => preg_replace('/\D+/', '', (string)($input['tax_id'] ?? '')) ?: '',
                'stamp' => !empty($input['stamp']) ? '1' : '0',
                'sync_sales' => !empty($input['sync_sales']) ? '1' : '0',
                'sync_payments' => !empty($input['sync_payments']) ? '1' : '0',
                'sync_expenses' => !empty($input['sync_expenses']) ? '1' : '0',
            ],
            'siigo' => [
                'enabled' => !empty($input['enabled']) ? '1' : '0',
                'username' => $input['username'] ?? '',
                'access_key' => $input['access_key'] ?? '',
                'partner_id' => preg_replace('/[^A-Za-z0-9]/', '', (string)($input['partner_id'] ?? 'SeriERP')) ?: 'SeriERP',
                'base_url' => $input['base_url'] ?? 'https://api.siigo.com',
                'document_id' => preg_replace('/\D+/', '', (string)($input['document_id'] ?? '')) ?: '',
                'seller_id' => preg_replace('/\D+/', '', (string)($input['seller_id'] ?? '')) ?: '',
                'payment_type_id' => preg_replace('/\D+/', '', (string)($input['payment_type_id'] ?? '')) ?: '',
                'tax_id' => preg_replace('/\D+/', '', (string)($input['tax_id'] ?? '')) ?: '',
                'account_group_id' => preg_replace('/\D+/', '', (string)($input['account_group_id'] ?? '')) ?: '',
                'stamp' => !empty($input['stamp']) ? '1' : '0',
                'sync_sales' => !empty($input['sync_sales']) ? '1' : '0',
            ],
            'factus' => [
                'enabled' => !empty($input['enabled']) ? '1' : '0',
                'client_id' => $input['client_id'] ?? '',
                'client_secret' => $input['client_secret'] ?? '',
                'username' => $input['username'] ?? '',
                'password' => $input['password'] ?? '',
                'base_url' => $input['base_url'] ?? 'https://api-sandbox.factus.com.co',
                'numbering_range_id' => preg_replace('/\D+/', '', (string)($input['numbering_range_id'] ?? '')) ?: '',
                'tax_rate' => (string)((float)($input['tax_rate'] ?? 0)),
                'municipality_id' => preg_replace('/\D+/', '', (string)($input['municipality_id'] ?? '')) ?: '',
                'sync_sales' => !empty($input['sync_sales']) ? '1' : '0',
            ],
            'dian' => [
                'enabled' => !empty($input['enabled']) ? '1' : '0',
                'nit' => $input['nit'] ?? '',
                'dv' => $input['dv'] ?? '',
                'legal_name' => $input['legal_name'] ?? '',
                'resolution_number' => $input['resolution_number'] ?? '',
                'prefix' => $input['prefix'] ?? '',
                'range_from' => $input['range_from'] ?? '',
                'range_to' => $input['range_to'] ?? '',
                'technical_key' => $input['technical_key'] ?? '',
                'software_id' => $input['software_id'] ?? '',
                'pin' => $input['pin'] ?? '',
                'environment' => in_array(($input['environment'] ?? ''), ['habilitacion', 'produccion'], true)
                    ? $input['environment']
                    : 'habilitacion',
                'sync_sales' => !empty($input['sync_sales']) ? '1' : '0',
            ],
            default => [],
        };

        $this->settings->saveBatch($provider, $fields, $secretKeys);

        if ($setActive) {
            $this->settings->setActiveProvider($provider);
        } elseif (array_key_exists('make_active', $input) && empty($input['make_active'])) {
            // no-op
        }
    }

    public function setActive(?string $provider): void
    {
        if ($provider !== null && $provider !== '' && !isset($this->providers[$provider])) {
            throw new \InvalidArgumentException('Proveedor desconocido');
        }
        $this->settings->setActiveProvider($provider ?: null);
    }

    public function test(string $provider): array
    {
        $instance = $this->provider($provider);
        if (!$instance) {
            return ['success' => false, 'message' => 'Proveedor no encontrado'];
        }
        return $instance->testConnection();
    }
}
