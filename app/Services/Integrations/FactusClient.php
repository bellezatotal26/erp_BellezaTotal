<?php

namespace SoftNova\Services\Integrations;

class FactusClient
{
    private array $config;
    private ?string $accessToken = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function hasCredentials(): bool
    {
        return trim((string)($this->config['client_id'] ?? '')) !== ''
            && trim((string)($this->config['client_secret'] ?? '')) !== ''
            && trim((string)($this->config['username'] ?? '')) !== ''
            && trim((string)($this->config['password'] ?? '')) !== '';
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
            return ['ok' => false, 'status' => 0, 'error' => 'Factus: faltan client_id, secret, usuario o password'];
        }

        $base = rtrim((string)($this->config['base_url'] ?? 'https://api-sandbox.factus.com.co'), '/');
        $response = HttpJsonClient::request(
            'POST',
            $base . '/oauth/token',
            [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            null,
            [
                'grant_type' => 'password',
                'client_id' => trim((string)$this->config['client_id']),
                'client_secret' => trim((string)$this->config['client_secret']),
                'username' => trim((string)$this->config['username']),
                'password' => trim((string)$this->config['password']),
            ],
            (int)($this->config['timeout'] ?? 30),
            ['factus.com.co']
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
                    'error' => $auth['error'] ?? 'No se pudo autenticar en Factus',
                ];
            }
        }

        $base = rtrim((string)($this->config['base_url'] ?? 'https://api-sandbox.factus.com.co'), '/');
        return HttpJsonClient::request(
            $method,
            $base . '/' . ltrim($path, '/'),
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            $body,
            null,
            (int)($this->config['timeout'] ?? 30),
            ['factus.com.co']
        );
    }
}
