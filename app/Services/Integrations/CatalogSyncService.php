<?php

namespace SoftNova\Services\Integrations;

use SoftNova\Services\StockService;
use SoftNova\Services\TenantOpsSchema;

/**
 * Importa productos de WooCommerce / Mercado Libre al inventario local,
 * diferenciados por source_channel + external_id.
 *
 * Política de stock (stock_authority por proveedor):
 * - store: la tienda manda (ajusta stock local al remoto)
 * - erp: el ERP manda (no toca stock en updates; altas quedan en 0)
 * - create_only: solo aplica stock remoto al crear; updates no tocan stock
 */
class CatalogSyncService
{
    public const STOCK_AUTHORITIES = ['store', 'erp', 'create_only'];

    private \PDO $db;
    private IntegrationSettingsService $settings;

    /** @var array<string, CatalogProviderInterface> */
    private array $providers = [];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        TenantOpsSchema::ensure($db);
        $this->settings = new IntegrationSettingsService($db);
        $wooCfg = $this->settings->providerConfig('woocommerce');
        $mlCfg = $this->settings->providerConfig('mercadolibre');
        $this->providers = [
            'woocommerce' => new WooCommerceConnector($wooCfg),
            'mercadolibre' => new MercadoLibreConnector($mlCfg, $this->settings),
        ];
    }

    public function settings(): IntegrationSettingsService
    {
        return $this->settings;
    }

    /** @return array<string, array> */
    public function statuses(): array
    {
        $out = [];
        foreach ($this->providers as $code => $provider) {
            $out[$code] = $provider->status() + [
                'label' => $provider->label(),
                'form' => $this->settings->all($code, true),
                'stock_authority' => $this->stockAuthority($code),
            ];
        }
        return $out;
    }

    public function provider(string $code): ?CatalogProviderInterface
    {
        return $this->providers[$code] ?? null;
    }

    public function mercadoLibre(): ?MercadoLibreConnector
    {
        $p = $this->providers['mercadolibre'] ?? null;
        return $p instanceof MercadoLibreConnector ? $p : null;
    }

    public function test(string $code): array
    {
        $p = $this->provider($code);
        if (!$p) {
            return ['success' => false, 'message' => 'Proveedor desconocido'];
        }
        return $p->testConnection();
    }

    public function stockAuthority(string $provider): string
    {
        $v = strtolower(trim((string)$this->settings->get($provider, 'stock_authority', 'create_only')));
        return in_array($v, self::STOCK_AUTHORITIES, true) ? $v : 'create_only';
    }

    public function saveProvider(string $provider, array $input): void
    {
        if (!isset($this->providers[$provider])) {
            throw new \InvalidArgumentException('Proveedor de catálogo desconocido');
        }
        $authority = strtolower(trim((string)($input['stock_authority'] ?? 'create_only')));
        if (!in_array($authority, self::STOCK_AUTHORITIES, true)) {
            $authority = 'create_only';
        }

        $secretKeys = match ($provider) {
            'woocommerce' => ['consumer_secret' => true],
            'mercadolibre' => [
                'access_token' => true,
                'refresh_token' => true,
                'client_secret' => true,
            ],
            default => [],
        };
        $fields = match ($provider) {
            'woocommerce' => [
                'enabled' => !empty($input['enabled']) ? '1' : '0',
                'store_url' => rtrim((string)($input['store_url'] ?? ''), '/'),
                'consumer_key' => (string)($input['consumer_key'] ?? ''),
                'consumer_secret' => (string)($input['consumer_secret'] ?? ''),
                'stock_authority' => $authority,
            ],
            'mercadolibre' => [
                'enabled' => !empty($input['enabled']) ? '1' : '0',
                'access_token' => (string)($input['access_token'] ?? ''),
                'refresh_token' => (string)($input['refresh_token'] ?? ''),
                'client_id' => (string)($input['client_id'] ?? ''),
                'client_secret' => (string)($input['client_secret'] ?? ''),
                'user_id' => preg_replace('/\D+/', '', (string)($input['user_id'] ?? '')) ?: '',
                'site_id' => (string)($input['site_id'] ?? 'MCO'),
                'base_url' => (string)($input['base_url'] ?? 'https://api.mercadolibre.com'),
                'stock_authority' => $authority,
            ],
            default => [],
        };
        $this->settings->saveBatch($provider, $fields, $secretKeys);

        // Recargar conector con config fresca
        if ($provider === 'mercadolibre') {
            $this->providers['mercadolibre'] = new MercadoLibreConnector(
                $this->settings->providerConfig('mercadolibre'),
                $this->settings
            );
        } elseif ($provider === 'woocommerce') {
            $this->providers['woocommerce'] = new WooCommerceConnector(
                $this->settings->providerConfig('woocommerce')
            );
        }
    }

    /**
     * @return array{created:int,updated:int,errors:int,skipped_stock:int,message?:string}
     */
    public function import(string $providerCode, int $limit = 100): array
    {
        $provider = $this->provider($providerCode);
        if (!$provider) {
            throw new \InvalidArgumentException('Proveedor inválido');
        }
        $status = $provider->status();
        if (empty($status['enabled']) || empty($status['configured'])) {
            throw new \RuntimeException($provider->label() . ' no está habilitado o configurado');
        }

        $authority = $this->stockAuthority($providerCode);
        $items = $provider->fetchProducts($limit);
        $created = 0;
        $updated = 0;
        $errors = 0;
        $skippedStock = 0;
        $stock = new StockService($this->db);

        foreach ($items as $item) {
            try {
                $extId = (string)($item['external_id'] ?? '');
                if ($extId === '') {
                    $errors++;
                    continue;
                }
                $existing = $this->db->prepare(
                    "SELECT id, stock FROM products
                     WHERE external_source = ? AND external_id = ? LIMIT 1"
                );
                $existing->execute([$providerCode, $extId]);
                $row = $existing->fetch();

                $name = trim((string)($item['name'] ?? 'Producto'));
                $code = trim((string)($item['code'] ?? ''));
                if ($code === '') {
                    $code = strtoupper(substr($providerCode, 0, 3)) . '-' . $extId;
                }
                $price = (float)($item['price'] ?? 0);
                $cost = (float)($item['cost'] ?? 0);
                $remoteStock = max(0, (int)($item['stock'] ?? 0));
                $desc = (string)($item['description'] ?? '');

                if ($row) {
                    $upd = $this->db->prepare(
                        "UPDATE products SET name = ?, code = ?, sale_price = ?,
                            purchase_price = IF(? > 0, ?, purchase_price),
                            description = IF(? = '', description, ?),
                            source_channel = ?, updated_at = NOW()
                         WHERE id = ?"
                    );
                    $upd->execute([
                        $name, $code, $price,
                        $cost, $cost,
                        $desc, $desc,
                        $providerCode,
                        $row['id'],
                    ]);

                    if ($authority === 'store') {
                        $diff = $remoteStock - (int)$row['stock'];
                        if ($diff > 0) {
                            $stock->increase((int)$row['id'], $diff, $providerCode, null, 'Sync stock ' . $providerCode);
                        } elseif ($diff < 0) {
                            try {
                                $stock->decrease((int)$row['id'], abs($diff), $providerCode, null, 'Sync stock ' . $providerCode);
                            } catch (\Throwable $e) {
                                // no bloquear si stock local menor
                            }
                        }
                    } else {
                        $skippedStock++;
                    }
                    $updated++;
                } else {
                    $ins = $this->db->prepare(
                        "INSERT INTO products
                            (code, name, product_type, description, purchase_price, sale_price,
                             stock, min_stock, unit, status, source_channel, external_source, external_id, created_by)
                         VALUES (?, ?, 'product', ?, ?, ?, 0, 5, 'UNIDAD', 'active', ?, ?, ?, ?)"
                    );
                    $ins->execute([
                        $code, $name, $desc ?: null, $cost, $price,
                        $providerCode, $providerCode, $extId,
                        $_SESSION['tenant_user_id'] ?? null,
                    ]);
                    $newId = (int)$this->db->lastInsertId();

                    // erp: altas en 0; store/create_only: cargar stock remoto inicial
                    if ($authority !== 'erp' && $remoteStock > 0) {
                        $stock->increase($newId, $remoteStock, $providerCode, null, 'Importación inicial ' . $providerCode);
                    } elseif ($remoteStock > 0) {
                        $skippedStock++;
                    }
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors++;
                error_log('Catalog import ' . $providerCode . ': ' . $e->getMessage());
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'skipped_stock' => $skippedStock,
        ];
    }
}
