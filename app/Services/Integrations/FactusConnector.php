<?php

namespace SoftNova\Services\Integrations;

/**
 * Factus (Colombia): factura electrónica DIAN.
 * El cliente y los ítems se envían inline en POST /v1/bills/validate,
 * por lo que no requiere resolver/crear entidades previamente.
 */
class FactusConnector implements InvoiceProviderInterface
{
    private FactusClient $client;
    private array $config;
    private bool $isActive;
    private ?IntegrationSettingsService $settings;

    public function __construct(array $config, bool $isActive = false, ?IntegrationSettingsService $settings = null)
    {
        $this->config = $config;
        $this->isActive = $isActive;
        $this->settings = $settings;
        $this->client = new FactusClient($config);
    }

    public function code(): string
    {
        return 'factus';
    }

    public function label(): string
    {
        return 'Factus';
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
                'base_url' => (string)($this->config['base_url'] ?? ''),
                'numbering_range_id' => (string)($this->config['numbering_range_id'] ?? ''),
            ],
        ];
    }

    public function testConnection(): array
    {
        if (!$this->client->hasCredentials()) {
            return ['success' => false, 'message' => 'Complete client_id, secret, usuario y password de Factus'];
        }

        $auth = $this->client->authenticate();
        if (!$auth['ok']) {
            return [
                'success' => false,
                'message' => $auth['error'] ?? 'No se pudo autenticar en Factus',
            ];
        }

        return [
            'success' => true,
            'message' => 'Conexión Factus OK (OAuth token obtenido)',
            'company' => [
                'token_type' => $auth['data']['token_type'] ?? 'Bearer',
                'expires_in' => $auth['data']['expires_in'] ?? null,
            ],
        ];
    }

    public function pushSale(array $sale, array $items = [], array $payments = []): array
    {
        if (!$this->client->isConfigured() || !filter_var($this->config['sync_sales'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Factus deshabilitado o sync de ventas inactivo',
            ];
        }
        if (empty($items)) {
            return ['success' => false, 'queued' => false, 'message' => 'La venta no tiene ítems'];
        }

        try {
            $customer = is_array($sale['customer'] ?? null) ? $sale['customer'] : [];
            $taxRate = (float)($this->config['tax_rate'] ?? 19);
            $unitMeasureId = (int)($this->config['unit_measure_id'] ?? 70);
            $municipalityId = trim((string)($this->config['municipality_id'] ?? ''));

            $factusItems = [];
            foreach ($items as $index => $item) {
                $localId = (int)($item['product_id'] ?? $item['id'] ?? 0);
                $code = trim((string)($item['code'] ?? $item['product_code'] ?? ''));
                if ($code === '') {
                    $code = $localId > 0 ? 'SERI-' . $localId : 'SERI-' . ($index + 1);
                }
                // discount_rate en % sobre el precio
                $qty = (float)($item['quantity'] ?? 1);
                $price = round((float)($item['unit_price'] ?? 0), 2);
                $lineDiscount = (float)($item['discount'] ?? 0);
                $gross = $qty * $price;
                $discountRate = ($gross > 0 && $lineDiscount > 0) ? round(($lineDiscount / $gross) * 100, 2) : 0;

                $factusItems[] = [
                    'code_reference' => $code,
                    'name' => mb_substr((string)($item['product_name'] ?? $item['name'] ?? 'Producto'), 0, 200),
                    'quantity' => $qty,
                    'discount_rate' => $discountRate,
                    'price' => $price,
                    'tax_rate' => number_format($taxRate, 2, '.', ''),
                    'unit_measure_id' => $unitMeasureId,
                    'standard_code_id' => 1,
                    'is_excluded' => $taxRate > 0 ? 0 : 1,
                    'tribute_id' => 1,
                    'withholding_taxes' => [],
                ];
            }

            $reference = trim((string)($sale['invoice_number'] ?? ''));
            if ($reference === '') {
                $reference = 'SERI-' . (int)($sale['id'] ?? 0);
            }

            $doc = preg_replace('/\D+/', '', (string)($customer['document_number'] ?? ''));
            $docType = strtoupper((string)($customer['document_type'] ?? 'CC'));
            // identification_document_id: 3=CC, 6=NIT, 5=CE, 7=PPT (tablas Factus)
            $idDocId = match ($docType) {
                'NIT' => 6,
                'CE' => 5,
                'PP', 'PPT' => 7,
                default => 3,
            };
            $legalOrg = ($docType === 'NIT') ? 1 : 2; // 1=jurídica, 2=natural
            $name = trim((string)($customer['name'] ?? $customer['company_name'] ?? 'Consumidor final'));
            if ($name === '') {
                $name = 'Consumidor final';
            }

            $customerPayload = [
                'identification' => $doc !== '' ? $doc : '222222222222',
                'legal_organization_id' => (string)$legalOrg,
                'tribute_id' => '21',
                'identification_document_id' => $idDocId,
                'names' => $name,
                'email' => (string)($customer['email'] ?? ''),
                'phone' => preg_replace('/\D+/', '', (string)($customer['phone'] ?? '')),
                'address' => (string)($customer['address'] ?? 'N/A'),
            ];
            if ($docType === 'NIT') {
                $customerPayload['company'] = $name;
            }
            if ($municipalityId !== '') {
                $customerPayload['municipality_id'] = $municipalityId;
            }

            $payload = [
                'document' => '01',
                'reference_code' => $reference,
                'observation' => 'Seri ERP ' . $reference,
                'payment_form' => in_array((string)($sale['payment_status'] ?? ''), ['pending', 'partial'], true)
                    ? '2' // crédito
                    : '1', // contado
                'payment_method_code' => $this->mapPaymentMethod((string)($sale['payment_method'] ?? 'cash')),
                'customer' => $customerPayload,
                'items' => $factusItems,
            ];

            $rangeId = (int)($this->config['numbering_range_id'] ?? 0);
            if ($rangeId > 0) {
                $payload['numbering_range_id'] = $rangeId;
            }

            $response = $this->client->request('POST', '/v1/bills/validate', $payload);
            if (!$response['ok']) {
                return [
                    'success' => false,
                    'queued' => false,
                    'message' => $this->extractError($response) ?: 'Error al crear factura en Factus',
                    'payload_preview' => $payload,
                    'response' => $response['data'] ?? null,
                ];
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $bill = $data['data']['bill'] ?? $data['bill'] ?? $data;
            $remoteId = (string)($bill['id'] ?? '');
            $number = (string)($bill['number'] ?? $bill['reference_code'] ?? $reference);
            $cufe = (string)($bill['cufe'] ?? '');

            return [
                'success' => true,
                'queued' => false,
                'message' => 'Factura validada en Factus/DIAN' . ($number !== '' ? ' #' . $number : ''),
                'external_id' => $remoteId !== '' ? $remoteId : $number,
                'external_number' => $number,
                'cufe' => $cufe,
                'data' => $data,
                'payload_preview' => $payload,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Excepción Factus: ' . $e->getMessage(),
            ];
        }
    }

    /** Códigos de método de pago DIAN. */
    private function mapPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'transfer' => '42',
            'card', 'credit_card' => '48',
            'debit_card', 'dataphone' => '49',
            'payment_link' => '47',
            'check' => '20',
            default => '10',
        };
    }

    private function extractError(array $response): string
    {
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $parts = [];
            if (!empty($data['message'])) {
                $parts[] = (string)$data['message'];
            }
            $errors = $data['data']['errors'] ?? $data['errors'] ?? null;
            if (is_array($errors)) {
                array_walk_recursive($errors, static function ($v) use (&$parts) {
                    if (is_scalar($v)) {
                        $parts[] = (string)$v;
                    }
                });
            }
            if ($parts) {
                return implode('; ', array_unique(array_filter($parts)));
            }
        }
        return (string)($response['error'] ?? '');
    }
}
