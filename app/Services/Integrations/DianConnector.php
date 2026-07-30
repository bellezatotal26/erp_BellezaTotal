<?php

namespace SoftNova\Services\Integrations;

/**
 * Integración DIAN directa (esqueleto).
 *
 * Importante: emitir a producción requiere ser Proveedor Tecnológico autorizado
 * o usar la facturación gratuita DIAN (manual). Este conector guarda datos
 * fiscales y deja listos los puntos de extensión (UBL/CUFE/envío).
 */
class DianConnector implements InvoiceProviderInterface
{
    private array $config;
    private bool $isActive;

    public function __construct(array $config, bool $isActive = false)
    {
        $this->config = $config;
        $this->isActive = $isActive;
    }

    public function code(): string
    {
        return 'dian';
    }

    public function label(): string
    {
        return 'DIAN (directo)';
    }

    public function hasCredentials(): bool
    {
        return trim((string)($this->config['nit'] ?? '')) !== ''
            && trim((string)($this->config['resolution_number'] ?? '')) !== ''
            && trim((string)($this->config['technical_key'] ?? '')) !== '';
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->hasCredentials();
    }

    public function status(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'configured' => $this->isConfigured(),
            'active' => $this->isActive,
            'sync' => [
                'sales' => filter_var($this->config['sync_sales'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            ],
            'meta' => [
                'nit' => (string)($this->config['nit'] ?? ''),
                'resolution_number' => (string)($this->config['resolution_number'] ?? ''),
                'prefix' => (string)($this->config['prefix'] ?? ''),
                'environment' => (string)($this->config['environment'] ?? 'habilitacion'),
            ],
        ];
    }

    public function testConnection(): array
    {
        if (!$this->hasCredentials()) {
            return [
                'success' => false,
                'message' => 'Complete NIT, resolución y clave técnica DIAN',
            ];
        }

        $missing = [];
        foreach (['software_id', 'pin', 'prefix', 'range_from', 'range_to'] as $field) {
            if (trim((string)($this->config[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        if ($missing) {
            return [
                'success' => false,
                'message' => 'Datos incompletos: ' . implode(', ', $missing)
                    . '. La conexión real a DIAN requiere software autorizado (PT).',
            ];
        }

        return [
            'success' => true,
            'message' => 'Datos DIAN guardados. Emisión directa requiere habilitación como Proveedor Tecnológico o factura gratuita DIAN.',
            'company' => [
                'nit' => $this->config['nit'],
                'resolution' => $this->config['resolution_number'],
                'environment' => $this->config['environment'] ?? 'habilitacion',
            ],
        ];
    }

    public function pushSale(array $sale, array $items = [], array $payments = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'DIAN deshabilitado o datos fiscales incompletos',
            ];
        }

        return [
            'success' => false,
            'queued' => true,
            'message' => 'Esqueleto DIAN: generación UBL 2.1 / CUFE / envío WS pendiente de autorización PT',
            'payload_preview' => [
                'sale_id' => $sale['id'] ?? null,
                'nit' => $this->config['nit'] ?? null,
                'prefix' => $this->config['prefix'] ?? null,
                'items' => count($items),
            ],
        ];
    }
}
