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
        $limit = max(1, min(100, $limit));
        $res = $this->request('GET', '/wp-json/wc/v3/products?per_page=' . $limit . '&status=publish');
        if (!$res['ok'] || !is_array($res['data'] ?? null)) {
            throw new \RuntimeException($res['error'] ?? 'Error al listar productos WooCommerce');
        }
        $out = [];
        foreach ($res['data'] as $p) {
            if (!is_array($p) || empty($p['id'])) {
                continue;
            }
            $out[] = [
                'external_id' => (string)$p['id'],
                'code' => (string)($p['sku'] ?? ('WOO-' . $p['id'])),
                'name' => (string)($p['name'] ?? 'Producto Woo'),
                'price' => (float)($p['regular_price'] ?? $p['price'] ?? 0),
                'cost' => 0.0,
                'stock' => isset($p['stock_quantity']) ? (int)$p['stock_quantity'] : 0,
                'description' => mb_substr(strip_tags((string)($p['short_description'] ?? $p['description'] ?? '')), 0, 500),
            ];
        }
        return $out;
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
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . http_build_query([
            'consumer_key' => trim((string)$this->config['consumer_key']),
            'consumer_secret' => trim((string)$this->config['consumer_secret']),
        ]);

        $host = parse_url($base, PHP_URL_HOST);
        $allowed = $host ? [$host] : [];

        return HttpJsonClient::request(
            $method,
            $url,
            ['Accept: application/json', 'Content-Type: application/json'],
            null,
            null,
            (int)($this->config['timeout'] ?? 30),
            $allowed
        );
    }
}
