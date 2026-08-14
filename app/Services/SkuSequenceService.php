<?php

namespace SoftNova\Services;

/**
 * Genera códigos SKU consecutivos según la configuración del tenant.
 *
 * Settings:
 *  - sku_prefix  Prefijo (ej. SKU-)
 *  - sku_next    Siguiente número
 *  - sku_pad     Ceros a la izquierda (3–12)
 */
class SkuSequenceService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{prefix:string,next:int,pad:int}
     */
    public function config(): array
    {
        $prefix = trim((string)SettingsCache::get($this->db, 'sku_prefix', 'SKU-'));
        if ($prefix === '') {
            $prefix = 'SKU-';
        }
        $pad = (int)SettingsCache::get($this->db, 'sku_pad', '6');
        if ($pad < 3) {
            $pad = 3;
        }
        if ($pad > 12) {
            $pad = 12;
        }
        $next = max(1, (int)SettingsCache::get($this->db, 'sku_next', '1'));

        return [
            'prefix' => substr($prefix, 0, 40),
            'next' => $next,
            'pad' => $pad,
        ];
    }

    /**
     * Reserva el siguiente SKU único (incrementa sku_next de forma segura).
     *
     * @return array{sku:string,next:int}
     */
    public function allocateNext(): array
    {
        $cfg = $this->config();
        $this->ensureSettingRow('sku_next', '1');

        $startedTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {
            $attempts = 0;
            $sku = '';
            $nextAfter = 1;

            while ($attempts < 80) {
                $attempts++;
                $stmt = $this->db->prepare(
                    "SELECT setting_value FROM settings WHERE setting_key = 'sku_next' FOR UPDATE"
                );
                $stmt->execute();
                $current = max(1, (int)$stmt->fetchColumn());

                $sku = $this->buildSku($cfg['prefix'], $current, $cfg['pad']);
                $nextAfter = $current + 1;
                $this->db->prepare(
                    "UPDATE settings SET setting_value = ? WHERE setting_key = 'sku_next'"
                )->execute([(string)$nextAfter]);

                if (!$this->skuExists($sku)) {
                    if ($startedTx) {
                        $this->db->commit();
                    }
                    SettingsCache::invalidate();
                    return ['sku' => $sku, 'next' => $nextAfter];
                }
            }

            if ($startedTx) {
                $this->db->rollBack();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        throw new \RuntimeException(
            'No se pudo generar un SKU libre. Revise prefijo/consecutivo en Configuración → Preferencias.'
        );
    }

    public function peekNext(): string
    {
        $cfg = $this->config();
        return $this->buildSku($cfg['prefix'], $cfg['next'], $cfg['pad']);
    }

    public function buildSku(string $prefix, int $sequence, int $pad): string
    {
        $prefix = substr(trim($prefix), 0, 40);
        if ($prefix === '') {
            $prefix = 'SKU-';
        }
        $pad = max(3, min(12, $pad));
        $num = str_pad((string)max(0, $sequence), $pad, '0', STR_PAD_LEFT);
        return $prefix . $num;
    }

    public function skuExists(string $sku, ?int $exceptProductId = null): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }
        $sql = "SELECT id FROM products WHERE code = ?";
        $params = [$sku];
        if ($exceptProductId) {
            $sql .= " AND id <> ?";
            $params[] = $exceptProductId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function ensureSettingRow(string $key, string $default): void
    {
        $stmt = $this->db->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        if (!$stmt->fetch()) {
            $ins = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $ins->execute([$key, $default]);
            SettingsCache::invalidate();
        }
    }
}
