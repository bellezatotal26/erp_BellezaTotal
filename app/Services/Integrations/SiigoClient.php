<?php

namespace SoftNova\Services\Integrations;

class SiigoClient
{
    private array $config;
    private ?string $accessToken = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function hasCredentials(): bool
    {
        return trim((string)($this->config['username'] ?? '')) !== ''
            && trim((string)($this->config['access_key'] ?? '')) !== '';
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->hasCredentials();
    }

    public function authenticate(): array
    {
        if (!$this->hasCredentials()) {
            return ['ok' => false, 'status' => 0, 'error' => 'Siigo: faltan usuario o access_key'];
        }

        $base = rtrim((string)($this->config['base_url'] ?? 'https://api.siigo.com'), '/');
        $partnerId = preg_replace('/[^A-Za-z0-9]/', '', (string)($this->config['partner_id'] ?? 'SeriERP')) ?: 'SeriERP';

        $response = HttpJsonClient::request(
            'POST',
            $base . '/auth',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Partner-Id: ' . $partnerId,
            ],
            [
                'username' => trim((string)$this->config['username']),
                'access_key' => trim((string)$this->config['access_key']),
            ],
            null,
            (int)($this->config['timeout'] ?? 30),
            ['siigo.com']
        );

        if ($response['ok'] && is_array($response['data'] ?? null)) {
            $this->accessToken = (string)($response['data']['access_token'] ?? '');
        }

        return $response;
    }

    /** @return array{ok:bool,status:int,data?:mixed,error?:string} */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            $auth = $this->authenticate();
            if (!$auth['ok'] || $this->accessToken === null || $this->accessToken === '') {
                return [
                    'ok' => false,
                    'status' => $auth['status'] ?? 0,
                    'error' => $auth['error'] ?? 'No se pudo autenticar en Siigo',
                ];
            }
        }

        $base = rtrim((string)($this->config['base_url'] ?? 'https://api.siigo.com'), '/');
        $partnerId = preg_replace('/[^A-Za-z0-9]/', '', (string)($this->config['partner_id'] ?? 'SeriERP')) ?: 'SeriERP';

        return HttpJsonClient::request(
            $method,
            $base . '/' . ltrim($path, '/'),
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
                'Partner-Id: ' . $partnerId,
            ],
            $body,
            null,
            (int)($this->config['timeout'] ?? 30),
            ['siigo.com']
        );
    }
}
