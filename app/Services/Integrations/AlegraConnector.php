<?php

namespace SoftNova\Services\Integrations;

/**
 * Alegra: resuelve/crea contacto e ítems, luego POST /invoices.
 * Colombia: paymentForm + paymentMethod + stamp electrónico opcional.
 */
class AlegraConnector implements InvoiceProviderInterface
{
    private AlegraClient $client;
    private array $config;
    private bool $isActive;
    private ?IntegrationSettingsService $settings;

    public function __construct(array $config, bool $isActive = false, ?IntegrationSettingsService $settings = null)
    {
        $this->config = $config;
        $this->isActive = $isActive;
        $this->settings = $settings;
        $this->client = new AlegraClient($config);
    }

    public function code(): string
    {
        return 'alegra';
    }

    public function label(): string
    {
        return 'Alegra';
    }

    public function status(): array
    {
        return [
            'enabled' => $this->client->isEnabled(),
            'configured' => $this->client->isConfigured(),
            'active' => $this->isActive,
            'sync' => [
                'sales' => filter_var($this->config['sync_sales'] ?? '0', FILTER_VALIDATE_BOOLEAN),
                'payments' => filter_var($this->config['sync_payments'] ?? '0', FILTER_VALIDATE_BOOLEAN),
                'expenses' => filter_var($this->config['sync_expenses'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            ],
            'meta' => [
                'email' => (string)($this->config['email'] ?? ''),
                'base_url' => (string)($this->config['base_url'] ?? ''),
                'tax_id' => (string)($this->config['tax_id'] ?? ''),
                'stamp' => filter_var($this->config['stamp'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    public function testConnection(): array
    {
        if (!$this->client->hasCredentials()) {
            return ['success' => false, 'message' => 'Ingrese email y token de Alegra en el formulario'];
        }

        $response = $this->client->ping();
        if (!$response['ok']) {
            return [
                'success' => false,
                'message' => $response['error'] ?? 'No se pudo conectar con Alegra',
            ];
        }

        $name = is_array($response['data'] ?? null)
            ? (string)($response['data']['name'] ?? 'empresa')
            : 'empresa';

        return [
            'success' => true,
            'message' => 'Conexión Alegra OK (' . $name . ')',
            'company' => $response['data'] ?? null,
        ];
    }

    public function pushSale(array $sale, array $items = [], array $payments = []): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'queued' => false, 'message' => 'Alegra no está habilitado o sin credenciales'];
        }
        if (!filter_var($this->config['sync_sales'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
            return ['success' => false, 'queued' => false, 'message' => 'Sync de ventas Alegra inactivo'];
        }
        if (empty($items)) {
            return ['success' => false, 'queued' => false, 'message' => 'La venta no tiene ítems'];
        }

        try {
            $customer = is_array($sale['customer'] ?? null) ? $sale['customer'] : [];
            $clientId = $this->resolveContactId($customer);
            if ($clientId === null) {
                return ['success' => false, 'queued' => false, 'message' => 'No se pudo resolver/crear el cliente en Alegra'];
            }

            $taxId = trim((string)($this->config['tax_id'] ?? ''));
            $alegraItems = [];
            foreach ($items as $item) {
                $itemId = $this->resolveItemId($item);
                if ($itemId === null) {
                    return [
                        'success' => false,
                        'queued' => false,
                        'message' => 'No se pudo resolver el ítem: ' . ($item['product_name'] ?? $item['name'] ?? '?'),
                    ];
                }
                $line = [
                    'id' => $itemId,
                    'price' => round((float)($item['unit_price'] ?? 0), 2),
                    'quantity' => (float)($item['quantity'] ?? 1),
                    'description' => (string)($item['product_name'] ?? $item['name'] ?? 'Producto'),
                ];
                if ($taxId !== '') {
                    $line['tax'] = [['id' => (int)$taxId]];
                }
                $alegraItems[] = $line;
            }

            $saleDate = $this->normalizeDate((string)($sale['sale_date'] ?? date('Y-m-d')));
            $isCredit = in_array((string)($sale['payment_status'] ?? ''), ['pending', 'partial'], true)
                || (($sale['payment_type'] ?? '') === 'credit');

            $payload = [
                'date' => $saleDate,
                'dueDate' => $isCredit
                    ? date('Y-m-d', strtotime($saleDate . ' +30 days'))
                    : $saleDate,
                'client' => $clientId,
                'status' => 'open',
                'paymentForm' => $isCredit ? 'CREDIT' : 'CASH',
                'paymentMethod' => $this->mapPaymentMethod((string)($sale['payment_method'] ?? 'cash')),
                'items' => $alegraItems,
                'anotation' => 'Seri ERP ' . (string)($sale['invoice_number'] ?? ''),
                'observations' => mb_substr((string)($sale['notes'] ?? ''), 0, 500),
            ];

            if (filter_var($this->config['stamp'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
                $payload['stamp'] = ['generateStamp' => true];
            }

            $response = $this->client->request('POST', '/invoices', $payload);
            if (!$response['ok']) {
                return [
                    'success' => false,
                    'queued' => false,
                    'message' => $response['error'] ?? 'Error al crear factura en Alegra',
                    'payload_preview' => $payload,
                    'response' => $response['data'] ?? null,
                ];
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $remoteId = (string)($data['id'] ?? '');

            return [
                'success' => true,
                'queued' => false,
                'message' => 'Factura creada en Alegra' . ($remoteId !== '' ? ' #' . $remoteId : ''),
                'external_id' => $remoteId,
                'external_number' => (string)($data['number'] ?? $data['id'] ?? ''),
                'data' => $data,
                'payload_preview' => $payload,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Excepción Alegra: ' . $e->getMessage(),
            ];
        }
    }

    private function resolveContactId(array $customer): ?int
    {
        $localId = (int)($customer['id'] ?? 0);
        if ($localId > 0) {
            $mapped = $this->getMap('customer', $localId);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $doc = trim((string)($customer['document_number'] ?? ''));
        $name = trim((string)($customer['name'] ?? $customer['company_name'] ?? 'Cliente general'));
        if ($name === '') {
            $name = 'Cliente general';
        }

        if ($doc !== '') {
            $found = $this->client->request('GET', '/contacts?identification=' . rawurlencode($doc));
            if ($found['ok'] && is_array($found['data'] ?? null)) {
                $list = $found['data'];
                if (isset($list['id'])) {
                    $list = [$list];
                }
                if (!empty($list[0]['id'])) {
                    $id = (int)$list[0]['id'];
                    if ($localId > 0) {
                        $this->setMap('customer', $localId, $id);
                    }
                    return $id;
                }
            }
        }

        $docType = strtoupper((string)($customer['document_type'] ?? 'CC'));
        $alegraType = match ($docType) {
            'NIT' => 'NIT',
            'CE' => 'CE',
            'PPT' => 'PPT',
            default => 'CC',
        };

        $body = [
            'name' => $name,
            'type' => ['client'],
            'email' => (string)($customer['email'] ?? ''),
            'phonePrimary' => (string)($customer['phone'] ?? ''),
            'address' => [
                'address' => (string)($customer['address'] ?? ''),
                'city' => (string)($customer['city'] ?? ''),
            ],
        ];
        if ($doc !== '') {
            $body['identificationObject'] = [
                'type' => $alegraType,
                'number' => $doc,
            ];
        }

        $created = $this->client->request('POST', '/contacts', $body);
        if (!$created['ok'] || empty($created['data']['id'])) {
            // Cliente genérico / consumidor final: buscar por nombre
            $byName = $this->client->request('GET', '/contacts?query=' . rawurlencode($name));
            if ($byName['ok'] && is_array($byName['data'] ?? null) && !empty($byName['data'][0]['id'])) {
                $id = (int)$byName['data'][0]['id'];
                if ($localId > 0) {
                    $this->setMap('customer', $localId, $id);
                }
                return $id;
            }
            return null;
        }

        $id = (int)$created['data']['id'];
        if ($localId > 0) {
            $this->setMap('customer', $localId, $id);
        }
        return $id;
    }

    private function resolveItemId(array $item): ?int
    {
        $localId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        if ($localId > 0) {
            $mapped = $this->getMap('product', $localId);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $name = trim((string)($item['product_name'] ?? $item['name'] ?? 'Producto'));
        $code = trim((string)($item['code'] ?? $item['product_code'] ?? ''));
        $price = round((float)($item['unit_price'] ?? $item['sale_price'] ?? 0), 2);
        $type = (($item['product_type'] ?? 'product') === 'service') ? 'service' : 'product';

        $query = $code !== '' ? $code : $name;
        $found = $this->client->request('GET', '/items?query=' . rawurlencode($query));
        if ($found['ok'] && is_array($found['data'] ?? null)) {
            foreach ($found['data'] as $remote) {
                if (!is_array($remote) || empty($remote['id'])) {
                    continue;
                }
                $ref = (string)($remote['reference'] ?? '');
                $rName = (string)($remote['name'] ?? '');
                if (($code !== '' && strcasecmp($ref, $code) === 0)
                    || strcasecmp($rName, $name) === 0) {
                    $id = (int)$remote['id'];
                    if ($localId > 0) {
                        $this->setMap('product', $localId, $id);
                    }
                    return $id;
                }
            }
        }

        $body = [
            'name' => $name,
            'price' => $price,
            'type' => $type,
            'description' => $name,
        ];
        if ($code !== '') {
            $body['reference'] = $code;
        }
        $taxId = trim((string)($this->config['tax_id'] ?? ''));
        if ($taxId !== '') {
            $body['tax'] = [['id' => (int)$taxId]];
        }

        $created = $this->client->request('POST', '/items', $body);
        if (!$created['ok'] || empty($created['data']['id'])) {
            return null;
        }
        $id = (int)$created['data']['id'];
        if ($localId > 0) {
            $this->setMap('product', $localId, $id);
        }
        return $id;
    }

    private function mapPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'transfer' => 'transfer',
            'card' => 'credit-card',
            'other' => 'other',
            default => 'cash',
        };
    }

    private function normalizeDate(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    private function getMap(string $entity, int $localId): ?int
    {
        if (!$this->settings) {
            return null;
        }
        $value = $this->settings->get('alegra', "map_{$entity}_{$localId}");
        return ($value !== null && $value !== '' && ctype_digit((string)$value)) ? (int)$value : null;
    }

    private function setMap(string $entity, int $localId, int $remoteId): void
    {
        if (!$this->settings) {
            return;
        }
        $this->settings->set('alegra', "map_{$entity}_{$localId}", (string)$remoteId, false);
    }
}
