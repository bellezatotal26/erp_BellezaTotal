<?php

namespace SoftNova\Services\Integrations;

/**
 * WooCommerce REST API (consumer key/secret) — importa productos al inventario.
 */
class WooCommerceConnector implements CatalogProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function code(): string
    {
        return 'woocommerce';
    }

    public function label(): string
    {
        return 'WooCommerce';
    }

    public function status(): array
    {
        return [
            'enabled' => !empty($this->config['enabled']),
            'configured' => $this->hasCredentials(),
            'meta' => [
                'store_url' => (string)($this->config['store_url'] ?? ''),
            ],
        ];
    }

    public function testConnection(): array
    {
        if (!$this->hasCredentials()) {
            return ['success' => false, 'message' => 'Configure URL de tienda, consumer key y secret'];
        }
        $res = $this->request('GET', '/wp-json/wc/v3/system_status');
        if (!$res['ok']) {
            // Fallback liviano: listar 1 producto
            $res = $this->request('GET', '/wp-json/wc/v3/products?per_page=1');
        }
        if (!$res['ok']) {
            return ['success' => false, 'message' => $res['error'] ?? 'No se pudo conectar a WooCommerce'];
        }
        return ['success' => true, 'message' => 'Conexión WooCommerce OK'];
    }

    public function fetchProducts(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $out = [];
        $page = 1;
        $perPage = min(100, $limit);

        while (count($out) < $limit) {
            $need = min($perPage, $limit - count($out));
            $res = $this->request(
                'GET',
                '/wp-json/wc/v3/products?per_page=' . $need . '&page=' . $page . '&status=publish&orderby=id&order=asc'
            );
            if (!$res['ok'] || !is_array($res['data'] ?? null)) {
                if ($page === 1) {
                    throw new \RuntimeException($res['error'] ?? 'Error al listar productos WooCommerce');
                }
                break;
            }
            $batch = $res['data'];
            if ($batch === []) {
                break;
            }
            foreach ($batch as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue;
                }
                $out[] = [
                    'external_id' => (string)$p['id'],
                    'code' => (string)($p['sku'] ?? ('WOO-' . $p['id'])),
                    'barcode' => $this->extractBarcode($p),
                    'name' => (string)($p['name'] ?? 'Producto Woo'),
                    'price' => (float)($p['regular_price'] ?? $p['price'] ?? 0),
                    'cost' => 0.0,
                    'stock' => isset($p['stock_quantity']) ? (int)$p['stock_quantity'] : 0,
                    'description' => mb_substr(strip_tags((string)($p['short_description'] ?? $p['description'] ?? '')), 0, 500),
                ];
                if (count($out) >= $limit) {
                    break 2;
                }
            }
            if (count($batch) < $need) {
                break;
            }
            $page++;
            if ($page > 50) {
                break;
            }
        }

        return $out;
    }

    private function extractBarcode(array $p): string
    {
        $meta = $p['meta_data'] ?? [];
        if (!is_array($meta)) {
            return '';
        }
        $keys = ['barcode', '_barcode', 'ean', '_ean', 'gtin', '_gtin', 'upc', '_upc'];
        foreach ($meta as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtolower((string)($row['key'] ?? ''));
            if (in_array($key, $keys, true)) {
                $val = trim((string)($row['value'] ?? ''));
                if ($val !== '') {
                    return mb_substr($val, 0, 64);
                }
            }
        }
        $gtin = trim((string)($p['global_unique_id'] ?? ''));
        return $gtin !== '' ? mb_substr($gtin, 0, 64) : '';
    }

    private function hasCredentials(): bool
    {
        return trim((string)($this->config['store_url'] ?? '')) !== ''
            && trim((string)($this->config['consumer_key'] ?? '')) !== ''
            && trim((string)($this->config['consumer_secret'] ?? '')) !== '';
    }

    private function request(string $method, string $path): array
    {
        $base = rtrim((string)$this->config['store_url'], '/');
        $url = $base . '/' . ltrim($path, '/');
        $key = trim((string)$this->config['consumer_key']);
        $secret = trim((string)$this->config['consumer_secret']);

        $host = parse_url($base, PHP_URL_HOST);
        $allowed = $host ? [$host] : [];

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($key . ':' . $secret),
        ];

        $res = HttpJsonClient::request(
            $method,
            $url,
            $headers,
            null,
            null,
            (int)($this->config['timeout'] ?? 30),
            $allowed
        );

        // Fallback: algunas tiendas solo aceptan query-string auth
        if (!$res['ok'] && in_array((int)($res['status'] ?? 0), [0, 401, 403], true)) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $urlQs = $url . $sep . http_build_query([
                'consumer_key' => $key,
                'consumer_secret' => $secret,
            ]);
            $res = HttpJsonClient::request(
                $method,
                $urlQs,
                ['Accept: application/json', 'Content-Type: application/json'],
                null,
                null,
                (int)($this->config['timeout'] ?? 30),
                $allowed
            );
        }

        return $res;
    }
}
