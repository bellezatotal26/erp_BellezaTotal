<?php

namespace SoftNova\Services\Integrations;

/**
 * Mercado Libre — OAuth (access + refresh) e importación de ítems del vendedor.
 */
class MercadoLibreConnector implements CatalogProviderInterface
{
    private array $config;
    private ?IntegrationSettingsService $settings;
    private bool $tokenRefreshed = false;

    public function __construct(array $config, ?IntegrationSettingsService $settings = null)
    {
        $this->config = $config;
        $this->settings = $settings;
    }

    public function code(): string
    {
        return 'mercadolibre';
    }

    public function label(): string
    {
        return 'Mercado Libre';
    }

    public function status(): array
    {
        $expiresAt = (int)($this->config['token_expires_at'] ?? 0);
        return [
            'enabled' => !empty($this->config['enabled']),
            'configured' => $this->hasCredentials(),
            'oauth_ready' => $this->hasOAuthApp(),
            'meta' => [
                'user_id' => (string)($this->config['user_id'] ?? ''),
                'site_id' => (string)($this->config['site_id'] ?? 'MCO'),
                'token_expires_at' => $expiresAt > 0 ? date('Y-m-d H:i', $expiresAt) : '',
                'has_refresh' => trim((string)($this->config['refresh_token'] ?? '')) !== '',
            ],
        ];
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $site = strtoupper((string)($this->config['site_id'] ?? 'MCO'));
        $authHost = match ($site) {
            'MLA' => 'https://auth.mercadolibre.com.ar',
            'MLM' => 'https://auth.mercadolibre.com.mx',
            'MLC' => 'https://auth.mercadolibre.cl',
            'MLB' => 'https://auth.mercadolivre.com.br',
            default => 'https://auth.mercadolibre.com.co',
        };
        $clientId = trim((string)($this->config['client_id'] ?? ''));
        return $authHost . '/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Intercambia code o refresh_token por access_token y persiste si hay settings.
     *
     * @return array{access_token:string,refresh_token?:string,expires_in?:int,user_id?:string}
     */
    public function exchangeToken(string $grantType, array $extra): array
    {
        $clientId = trim((string)($this->config['client_id'] ?? ''));
        $clientSecret = trim((string)($this->config['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Configure Client ID y Client Secret de Mercado Libre');
        }

        $body = array_merge([
            'grant_type' => $grantType,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ], $extra);

        $res = HttpJsonClient::request(
            'POST',
            'https://api.mercadolibre.com/oauth/token',
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            null,
            $body,
            (int)($this->config['timeout'] ?? 30),
            ['mercadolibre.com', 'mercadolibre.com.co']
        );
        if (!$res['ok'] || !is_array($res['data'] ?? null)) {
            throw new \RuntimeException($res['error'] ?? 'No se pudo obtener token de Mercado Libre');
        }

        $data = $res['data'];
        $access = (string)($data['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('Respuesta OAuth sin access_token');
        }

        $expiresIn = (int)($data['expires_in'] ?? 21600);
        $expiresAt = time() + max(60, $expiresIn - 120);
        $refresh = (string)($data['refresh_token'] ?? '');
        $userId = isset($data['user_id']) ? (string)$data['user_id'] : '';

        $this->config['access_token'] = $access;
        if ($refresh !== '') {
            $this->config['refresh_token'] = $refresh;
        }
        $this->config['token_expires_at'] = (string)$expiresAt;
        if ($userId !== '') {
            $this->config['user_id'] = $userId;
        }

        if ($this->settings) {
            $this->settings->set('mercadolibre', 'access_token', $access, true);
            if ($refresh !== '') {
                $this->settings->set('mercadolibre', 'refresh_token', $refresh, true);
            }
            $this->settings->set('mercadolibre', 'token_expires_at', (string)$expiresAt, false);
            if ($userId !== '') {
                $this->settings->set('mercadolibre', 'user_id', $userId, false);
            }
        }

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $expiresIn,
            'user_id' => $userId,
        ];
    }

    public function refreshAccessToken(): bool
    {
        $refresh = trim((string)($this->config['refresh_token'] ?? ''));
        if ($refresh === '') {
            return false;
        }
        $this->exchangeToken('refresh_token', ['refresh_token' => $refresh]);
        $this->tokenRefreshed = true;
        return true;
    }

    public function testConnection(): array
    {
        if (!$this->hasCredentials() && !$this->hasOAuthApp()) {
            return ['success' => false, 'message' => 'Configure OAuth (Client ID/Secret) o un access_token'];
        }
        try {
            $this->ensureValidToken();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $path = !empty($this->config['user_id'])
            ? '/users/' . rawurlencode((string)$this->config['user_id'])
            : '/users/me';
        $res = $this->request('GET', $path);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $res['error'] ?? 'No se pudo conectar a Mercado Libre'];
        }
        $nick = is_array($res['data'] ?? null) ? (string)($res['data']['nickname'] ?? $res['data']['id'] ?? '') : '';
        $extra = $this->tokenRefreshed ? ' (token renovado)' : '';
        return ['success' => true, 'message' => 'Conexión Mercado Libre OK' . ($nick !== '' ? ' (' . $nick . ')' : '') . $extra];
    }

    public function fetchProducts(int $limit = 100): array
    {
        $this->ensureValidToken();
        $limit = max(1, min(100, $limit));
        $userId = trim((string)($this->config['user_id'] ?? ''));
        if ($userId === '') {
            $me = $this->request('GET', '/users/me');
            if (!$me['ok'] || empty($me['data']['id'])) {
                throw new \RuntimeException($me['error'] ?? 'No se pudo obtener el user_id de ML');
            }
            $userId = (string)$me['data']['id'];
            $this->config['user_id'] = $userId;
            if ($this->settings) {
                $this->settings->set('mercadolibre', 'user_id', $userId, false);
            }
        }

        $search = $this->request(
            'GET',
            '/users/' . rawurlencode($userId) . '/items/search?limit=' . $limit . '&status=active'
        );
        if (!$search['ok']) {
            throw new \RuntimeException($search['error'] ?? 'Error al listar ítems ML');
        }
        $ids = $search['data']['results'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 20) as $chunk) {
            $res = $this->request('GET', '/items?ids=' . rawurlencode(implode(',', $chunk)));
            if (!$res['ok'] || !is_array($res['data'] ?? null)) {
                continue;
            }
            foreach ($res['data'] as $row) {
                $body = is_array($row['body'] ?? null) ? $row['body'] : (is_array($row) ? $row : null);
                if (!$body || empty($body['id'])) {
                    continue;
                }
                $out[] = [
                    'external_id' => (string)$body['id'],
                    'code' => (string)($body['seller_custom_field'] ?? $body['id']),
                    'name' => (string)($body['title'] ?? 'Producto ML'),
                    'price' => (float)($body['price'] ?? 0),
                    'cost' => 0.0,
                    'stock' => (int)($body['available_quantity'] ?? 0),
                    'description' => '',
                ];
            }
        }
        return $out;
    }

    private function ensureValidToken(): void
    {
        $token = trim((string)($this->config['access_token'] ?? ''));
        $expiresAt = (int)($this->config['token_expires_at'] ?? 0);
        $needsRefresh = $token === '' || ($expiresAt > 0 && time() >= $expiresAt);

        if ($needsRefresh) {
            if (!$this->refreshAccessToken()) {
                if ($token === '') {
                    throw new \RuntimeException('Sin access_token. Use «Conectar con Mercado Libre» o pegue un token.');
                }
                // Token sin refresh: intentar igual (puede seguir vigente)
            }
        }
    }

    private function hasOAuthApp(): bool
    {
        return trim((string)($this->config['client_id'] ?? '')) !== ''
            && trim((string)($this->config['client_secret'] ?? '')) !== '';
    }

    private function hasCredentials(): bool
    {
        return trim((string)($this->config['access_token'] ?? '')) !== ''
            || trim((string)($this->config['refresh_token'] ?? '')) !== '';
    }

    private function request(string $method, string $path): array
    {
        $base = rtrim((string)($this->config['base_url'] ?? 'https://api.mercadolibre.com'), '/');
        $res = HttpJsonClient::request(
            $method,
            $base . '/' . ltrim($path, '/'),
            [
                'Accept: application/json',
                'Authorization: Bearer ' . trim((string)$this->config['access_token']),
            ],
            null,
            null,
            (int)($this->config['timeout'] ?? 30),
            ['mercadolibre.com', 'mercadolibre.com.co']
        );

        // Renovar una vez ante 401
        if (($res['status'] ?? 0) === 401 && !$this->tokenRefreshed && $this->refreshAccessToken()) {
            return HttpJsonClient::request(
                $method,
                $base . '/' . ltrim($path, '/'),
                [
                    'Accept: application/json',
                    'Authorization: Bearer ' . trim((string)$this->config['access_token']),
                ],
                null,
                null,
                (int)($this->config['timeout'] ?? 30),
                ['mercadolibre.com', 'mercadolibre.com.co']
            );
        }

        return $res;
    }
}
