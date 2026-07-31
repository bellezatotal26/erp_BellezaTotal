<?php

namespace SoftNova\Services\Integrations;

/**
 * Siigo: autentica (JWT), resuelve/crea cliente y productos, luego POST /v1/invoices.
 * Requiere en config: document_id, seller_id, payment_type_id (IDs de Siigo Nube).
 */
class SiigoConnector implements InvoiceProviderInterface
{
    private SiigoClient $client;
    private array $config;
    private bool $isActive;
    private ?IntegrationSettingsService $settings;

    public function __construct(array $config, bool $isActive = false, ?IntegrationSettingsService $settings = null)
    {
        $this->config = $config;
        $this->isActive = $isActive;
        $this->settings = $settings;
        $this->client = new SiigoClient($config);
    }

    public function code(): string
    {
        return 'siigo';
    }

    public function label(): string
    {
        return 'Siigo';
    }

    public function status(): array
    {
        return [
            'enabled' => $this->client->isEnabled(),
            'configured' => $this->client->isConfigured(),
            'active' => $this->isActive,
            'sync' => [
                'sales' => filter_var($this->config['sync_sales'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            ],
            'meta' => [
                'username' => (string)($this->config['username'] ?? ''),
                'partner_id' => (string)($this->config['partner_id'] ?? 'SeriERP'),
                'document_id' => (string)($this->config['document_id'] ?? ''),
                'seller_id' => (string)($this->config['seller_id'] ?? ''),
                'payment_type_id' => (string)($this->config['payment_type_id'] ?? ''),
                'stamp' => filter_var($this->config['stamp'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    public function testConnection(): array
    {
        if (!$this->client->hasCredentials()) {
            return ['success' => false, 'message' => 'Ingrese usuario y access_key de Siigo'];
        }

        $auth = $this->client->authenticate();
        if (!$auth['ok']) {
            return [
                'success' => false,
                'message' => $auth['error'] ?? 'No se pudo autenticar en Siigo',
            ];
        }

        return [
            'success' => true,
            'message' => 'Conexión Siigo OK (token JWT obtenido)',
            'company' => $auth['data'] ?? null,
        ];
    }

    public function pushSale(array $sale, array $items = [], array $payments = []): array
    {
        if (!$this->client->isConfigured() || !filter_var($this->config['sync_sales'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Siigo deshabilitado o sync de ventas inactivo',
            ];
        }
        if (empty($items)) {
            return ['success' => false, 'queued' => false, 'message' => 'La venta no tiene ítems'];
        }

        $documentId = (int)($this->config['document_id'] ?? 0);
        $sellerId = (int)($this->config['seller_id'] ?? 0);
        $paymentTypeId = (int)($this->config['payment_type_id'] ?? 0);
        if ($documentId <= 0 || $sellerId <= 0 || $paymentTypeId <= 0) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Configure document_id, seller_id y payment_type_id de Siigo (consulte /document-types, /users y /payment-types)',
            ];
        }

        try {
            $customer = is_array($sale['customer'] ?? null) ? $sale['customer'] : [];
            $identification = $this->resolveCustomerIdentification($customer);
            if ($identification === null) {
                return ['success' => false, 'queued' => false, 'message' => 'No se pudo resolver/crear el cliente en Siigo'];
            }

            $taxId = (int)($this->config['tax_id'] ?? 0);
            $siigoItems = [];
            $total = 0.0;
            foreach ($items as $item) {
                $code = $this->resolveProductCode($item);
                if ($code === null) {
                    return [
                        'success' => false,
                        'queued' => false,
                        'message' => 'No se pudo resolver el producto: ' . ($item['product_name'] ?? $item['name'] ?? '?'),
                    ];
                }
                $qty = (float)($item['quantity'] ?? 1);
                $price = round((float)($item['unit_price'] ?? 0), 2);
                $discount = round((float)($item['discount'] ?? 0), 2);
                $line = [
                    'code' => $code,
                    'description' => (string)($item['product_name'] ?? $item['name'] ?? 'Producto'),
                    'quantity' => $qty,
                    'price' => $price,
                ];
                if ($discount > 0) {
                    $line['discount'] = $discount;
                }
                if ($taxId > 0) {
                    $line['taxes'] = [['id' => $taxId]];
                }
                $siigoItems[] = $line;
                $total += ($qty * $price) - $discount;
            }

            $saleTotal = round((float)($sale['total'] ?? $total), 2);
            if ($saleTotal <= 0) {
                $saleTotal = round($total, 2);
            }
            $saleDate = $this->normalizeDate((string)($sale['sale_date'] ?? date('Y-m-d')));
            $isCredit = in_array((string)($sale['payment_status'] ?? ''), ['pending', 'partial'], true)
                || (($sale['payment_type'] ?? '') === 'credit');

            $payload = [
                'document' => ['id' => $documentId],
                'date' => $saleDate,
                'customer' => [
                    'identification' => $identification,
                    'branch_office' => 0,
                ],
                'seller' => $sellerId,
                'observations' => 'Seri ERP ' . (string)($sale['invoice_number'] ?? ''),
                'items' => $siigoItems,
                'payments' => [[
                    'id' => $paymentTypeId,
                    'value' => $saleTotal,
                    'due_date' => $isCredit ? date('Y-m-d', strtotime($saleDate . ' +30 days')) : $saleDate,
                ]],
            ];

            if (filter_var($this->config['stamp'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
                $payload['stamp'] = ['send' => true];
                $payload['mail'] = ['send' => false];
            }

            $response = $this->client->request('POST', '/v1/invoices', $payload);
            if (!$response['ok']) {
                return [
                    'success' => false,
                    'queued' => false,
                    'message' => $this->extractError($response) ?: 'Error al crear factura en Siigo',
                    'payload_preview' => $payload,
                    'response' => $response['data'] ?? null,
                ];
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $remoteId = (string)($data['id'] ?? '');
            $number = (string)($data['number'] ?? $data['name'] ?? '');

            return [
                'success' => true,
                'queued' => false,
                'message' => 'Factura creada en Siigo' . ($number !== '' ? ' #' . $number : ''),
                'external_id' => $remoteId,
                'external_number' => $number,
                'data' => $data,
                'payload_preview' => $payload,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Excepción Siigo: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Devuelve la identificación (documento) del cliente en Siigo, creándolo si no existe.
     */
    private function resolveCustomerIdentification(array $customer): ?string
    {
        $doc = preg_replace('/\D+/', '', (string)($customer['document_number'] ?? ''));

        if ($doc === '' || $doc === null) {
            // Consumidor final por defecto de Siigo (CC 222222222222)
            $doc = '222222222222';
        }

        // Verificar si existe
        $found = $this->client->request('GET', '/v1/customers?identification=' . rawurlencode($doc));
        if ($found['ok']) {
            $results = $found['data']['results'] ?? $found['data'] ?? [];
            if (is_array($results) && !empty($results[0]['id'])) {
                return $doc;
            }
        }

        $name = trim((string)($customer['name'] ?? $customer['company_name'] ?? 'Cliente general'));
        if ($name === '') {
            $name = 'Cliente general';
        }
        $docType = strtoupper((string)($customer['document_type'] ?? 'CC'));
        $personType = ($docType === 'NIT') ? 'Company' : 'Person';
        $idType = match ($docType) {
            'NIT' => '31',
            'CE' => '22',
            'PP', 'PPT' => '41',
            default => '13', // Cédula de ciudadanía
        };

        $body = [
            'person_type' => $personType,
            'id_type' => $idType,
            'identification' => $doc,
            'name' => $personType === 'Company' ? [$name] : $this->splitName($name),
            'address' => [
                'address' => (string)($customer['address'] ?? 'N/A'),
                'city' => [
                    'country_code' => 'Co',
                    'state_code' => (string)($this->config['state_code'] ?? '11'),
                    'city_code' => (string)($this->config['city_code'] ?? '11001'),
                ],
            ],
        ];
        $email = trim((string)($customer['email'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string)($customer['phone'] ?? ''));
        if ($email !== '' || $phone !== '') {
            $contact = ['first_name' => $name];
            if ($email !== '') {
                $contact['email'] = $email;
            }
            if ($phone !== '') {
                $contact['phone'] = ['number' => $phone];
            }
            $body['contacts'] = [$contact];
        }
        $groupId = (int)($this->config['account_group_id'] ?? 0);
        if ($groupId > 0) {
            $body['account_group'] = ['id' => $groupId];
        }

        $created = $this->client->request('POST', '/v1/customers', $body);
        if ($created['ok'] && !empty($created['data']['id'])) {
            return $doc;
        }

        // Si ya existía (409) o creado, devolvemos el doc igualmente
        if (($created['status'] ?? 0) === 409) {
            return $doc;
        }

        return null;
    }

    /**
     * Devuelve el código del producto en Siigo, creándolo si no existe.
     */
    private function resolveProductCode(array $item): ?string
    {
        $localId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        $code = trim((string)($item['code'] ?? $item['product_code'] ?? ''));
        if ($code === '' && $localId > 0) {
            $code = 'SERI-' . $localId;
        }
        if ($code === '') {
            $code = 'SERI-' . substr(md5((string)($item['product_name'] ?? 'item')), 0, 8);
        }

        if ($localId > 0) {
            $mapped = $this->getMap('product', $localId);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // ¿Existe por código?
        $found = $this->client->request('GET', '/v1/products?code=' . rawurlencode($code));
        if ($found['ok']) {
            $results = $found['data']['results'] ?? $found['data'] ?? [];
            if (is_array($results) && !empty($results[0]['id'])) {
                if ($localId > 0) {
                    $this->setMap('product', $localId, $code);
                }
                return $code;
            }
        }

        $name = trim((string)($item['product_name'] ?? $item['name'] ?? 'Producto'));
        $price = round((float)($item['unit_price'] ?? $item['sale_price'] ?? 0), 2);
        $type = (($item['product_type'] ?? 'product') === 'service') ? 'Service' : 'Product';

        $body = [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'stock_control' => false,
            'prices' => [[
                'currency_code' => 'COP',
                'price_list' => [['position' => 1, 'value' => $price]],
            ]],
        ];
        $groupId = (int)($this->config['account_group_id'] ?? 0);
        if ($groupId > 0) {
            $body['account_group'] = ['id' => $groupId];
        }
        $taxId = (int)($this->config['tax_id'] ?? 0);
        if ($taxId > 0) {
            $body['taxes'] = [['id' => $taxId]];
        }

        $created = $this->client->request('POST', '/v1/products', $body);
        if ($created['ok'] && !empty($created['data']['id'])) {
            if ($localId > 0) {
                $this->setMap('product', $localId, $code);
            }
            return $code;
        }
        if (($created['status'] ?? 0) === 409) {
            // Ya existía con ese código
            if ($localId > 0) {
                $this->setMap('product', $localId, $code);
            }
            return $code;
        }

        return null;
    }

    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [$full];
        if (count($parts) === 1) {
            return [$parts[0], '.'];
        }
        $first = array_shift($parts);
        return [$first, implode(' ', $parts)];
    }

    private function extractError(array $response): string
    {
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            if (!empty($data['Errors']) && is_array($data['Errors'])) {
                $msgs = array_map(static fn($e) => (string)($e['Message'] ?? $e['message'] ?? ''), $data['Errors']);
                return implode('; ', array_filter($msgs));
            }
            if (!empty($data['message'])) {
                return (string)$data['message'];
            }
        }
        return (string)($response['error'] ?? '');
    }

    private function normalizeDate(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    private function getMap(string $entity, int $localId): ?string
    {
        if (!$this->settings) {
            return null;
        }
        $value = $this->settings->get('siigo', "map_{$entity}_{$localId}");
        return ($value !== null && $value !== '') ? (string)$value : null;
    }

    private function setMap(string $entity, int $localId, string $remoteRef): void
    {
        if (!$this->settings) {
            return;
        }
        $this->settings->set('siigo', "map_{$entity}_{$localId}", $remoteRef, false);
    }
}
