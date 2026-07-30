<?php

namespace SoftNova\Services\Integrations;

use SoftNova\Core\Security;

/**
 * Credenciales de integración por tenant (no .env global).
 * Cada empresa guarda su propio proveedor y tokens.
 */
class IntegrationSettingsService
{
    private static array $schemaDone = [];

    public function __construct(private \PDO $db)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $key = spl_object_id($this->db);
        if (isset(self::$schemaDone[$key])) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS integration_settings (
                provider VARCHAR(30) NOT NULL,
                setting_key VARCHAR(80) NOT NULL,
                setting_value TEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (provider, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaDone[$key] = true;
    }

    public function getActiveProvider(): ?string
    {
        $value = $this->get('system', 'active_provider', '');
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    public function setActiveProvider(?string $provider): void
    {
        $allowed = ['alegra', 'siigo', 'factus', 'dian', ''];
        if (!in_array((string)$provider, $allowed, true)) {
            throw new \InvalidArgumentException('Proveedor de facturación inválido');
        }
        $this->set('system', 'active_provider', $provider ?: '', false);
    }

    public function all(string $provider, bool $maskSecrets = true): array
    {
        $stmt = $this->db->prepare(
            "SELECT setting_key, setting_value, is_secret
             FROM integration_settings WHERE provider = ?"
        );
        $stmt->execute([$provider]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $value = (string)($row['setting_value'] ?? '');
            if ((int)$row['is_secret'] === 1 && $value !== '') {
                $value = Security::decrypt($value) ?? '';
                if ($maskSecrets) {
                    $out[$row['setting_key']] = $this->mask($value);
                    $out[$row['setting_key'] . '_set'] = $value !== '';
                    continue;
                }
            }
            $out[$row['setting_key']] = $value;
        }
        return $out;
    }

    public function get(string $provider, string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT setting_value, is_secret FROM integration_settings
             WHERE provider = ? AND setting_key = ? LIMIT 1"
        );
        $stmt->execute([$provider, $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $default;
        }
        $value = (string)($row['setting_value'] ?? '');
        if ((int)$row['is_secret'] === 1 && $value !== '') {
            return Security::decrypt($value) ?? $default;
        }
        return $value;
    }

    public function set(string $provider, string $key, ?string $value, bool $isSecret = false): void
    {
        $store = $value;
        if ($isSecret && $value !== null && $value !== '') {
            $store = Security::encrypt($value);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO integration_settings (provider, setting_key, setting_value, is_secret)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                 is_secret = VALUES(is_secret)"
        );
        $stmt->execute([$provider, $key, $store, $isSecret ? 1 : 0]);
    }

    /**
     * Guarda un lote. Campos secretos vacíos conservan el valor anterior.
     *
     * @param array<string,string|null> $data
     * @param array<string,bool> $secretKeys key => true si es secreto
     */
    public function saveBatch(string $provider, array $data, array $secretKeys = []): void
    {
        foreach ($data as $key => $value) {
            $key = (string)$key;
            $isSecret = !empty($secretKeys[$key]);
            $value = $value === null ? '' : trim((string)$value);

            if ($isSecret && $value === '') {
                continue; // conservar token actual
            }
            if ($isSecret && $value === '********') {
                continue;
            }

            $this->set($provider, $key, $value, $isSecret);
        }
    }

    public function providerConfig(string $provider): array
    {
        $defaults = (array)\SoftNova\Core\config("integrations.providers.{$provider}", []);
        $stored = $this->all($provider, false);
        return array_merge($defaults, $stored, [
            'enabled' => filter_var($stored['enabled'] ?? '0', FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 4) {
            return '********';
        }
        return str_repeat('*', max(8, $len - 4)) . substr($value, -4);
    }
}
