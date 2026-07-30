<?php

namespace SoftNova\Services\Integrations;

class AlegraClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function hasCredentials(): bool
    {
        return trim((string)($this->config['email'] ?? '')) !== ''
            && trim((string)($this->config['token'] ?? '')) !== '';
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->hasCredentials();
    }

    /** @return array{ok:bool,status:int,data?:mixed,error?:string} */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->hasCredentials()) {
            return ['ok' => false, 'status' => 0, 'error' => 'Alegra: faltan email o token'];
        }

        $base = rtrim((string)($this->config['base_url'] ?? 'https://api.alegra.com/api/v1'), '/');
        $url = $base . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(
                trim((string)$this->config['email']) . ':' . trim((string)$this->config['token'])
            ),
        ];

        return HttpJsonClient::request(
            $method,
            $url,
            $headers,
            $body,
            null,
            (int)($this->config['timeout'] ?? 30),
            ['alegra.com']
        );
    }

    public function ping(): array
    {
        return $this->request('GET', '/company');
    }
}
