<?php

namespace SoftNova\Services;

/**
 * Genera códigos EAN/UPC consecutivos según la configuración del tenant.
 *
 * Settings:
 *  - ean_prefix      Prefijo numérico (ej. 200 para uso interno)
 *  - ean_next        Siguiente número de cuerpo
 *  - ean_length      8 u 13
 *  - ean_check_digit 1 = calcular dígito de control EAN
 */
class BarcodeSequenceService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{prefix:string,next:int,length:int,check_digit:bool}
     */
    public function config(): array
    {
        $prefix = preg_replace('/\D+/', '', SettingsCache::get($this->db, 'ean_prefix', '200')) ?? '';
        if ($prefix === '') {
            $prefix = '200';
        }
        $length = (int)SettingsCache::get($this->db, 'ean_length', '13');
        if (!in_array($length, [8, 13], true)) {
            $length = 13;
        }
        $next = max(1, (int)SettingsCache::get($this->db, 'ean_next', '1'));
        $check = SettingsCache::get($this->db, 'ean_check_digit', '1') !== '0';

        return [
            'prefix' => $prefix,
            'next' => $next,
            'length' => $length,
            'check_digit' => $check,
        ];
    }

    /**
     * Reserva el siguiente EAN único (incrementa ean_next de forma segura).
     *
     * @return array{barcode:string,next:int}
     */
    public function allocateNext(): array
    {
        $prefix = preg_replace('/\D+/', '', SettingsCache::get($this->db, 'ean_prefix', '200')) ?? '';
        if ($prefix === '') {
            $prefix = '200';
        }
        $length = (int)SettingsCache::get($this->db, 'ean_length', '13');
        if (!in_array($length, [8, 13], true)) {
            $length = 13;
        }
        $withCheck = SettingsCache::get($this->db, 'ean_check_digit', '1') !== '0';

        $this->ensureSettingRow('ean_next', '1');

        $startedTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {
            $attempts = 0;
            $barcode = '';
            $nextAfter = 1;

            while ($attempts < 80) {
                $attempts++;
                $stmt = $this->db->prepare(
                    "SELECT setting_value FROM settings WHERE setting_key = 'ean_next' FOR UPDATE"
                );
                $stmt->execute();
                $current = max(1, (int)$stmt->fetchColumn());

                $barcode = $this->buildBarcode($prefix, $current, $length, $withCheck);
                $nextAfter = $current + 1;
                $this->db->prepare(
                    "UPDATE settings SET setting_value = ? WHERE setting_key = 'ean_next'"
                )->execute([(string)$nextAfter]);

                if (!$this->barcodeExists($barcode)) {
                    if ($startedTx) {
                        $this->db->commit();
                    }
                    SettingsCache::invalidate();
                    return ['barcode' => $barcode, 'next' => $nextAfter];
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
            'No se pudo generar un EAN libre. Revise prefijo/longitud en Configuración → Preferencias.'
        );
    }

    /**
     * Vista previa del próximo EAN sin consumir la secuencia.
     */
    public function peekNext(): string
    {
        $cfg = $this->config();
        return $this->buildBarcode($cfg['prefix'], $cfg['next'], $cfg['length'], $cfg['check_digit']);
    }

    public function buildBarcode(string $prefix, int $sequence, int $length, bool $withCheck): string
    {
        $length = in_array($length, [8, 13], true) ? $length : 13;
        $bodyLen = $withCheck ? ($length - 1) : $length;
        $prefix = preg_replace('/\D+/', '', $prefix) ?? '';
        if ($prefix === '') {
            $prefix = '200';
        }
        if (strlen($prefix) >= $bodyLen) {
            $prefix = substr($prefix, 0, max(1, $bodyLen - 1));
        }
        $seqWidth = max(1, $bodyLen - strlen($prefix));
        $seq = (string)max(0, $sequence);
        if (strlen($seq) > $seqWidth) {
            // Desborde: avanza de forma cíclica visible sin colisionar el consecutivo interno.
            $seq = substr($seq, -$seqWidth);
        }
        $body = $prefix . str_pad($seq, $seqWidth, '0', STR_PAD_LEFT);
        if (strlen($body) > $bodyLen) {
            $body = substr($body, -$bodyLen);
        }
        if (!$withCheck) {
            return $body;
        }
        return $body . (string)$this->eanCheckDigit($body);
    }

    /**
     * Dígito de control GS1 (EAN-8 / EAN-13): pesos desde la derecha del cuerpo
     * (dígito más a la derecha ×1, luego ×3, ×1…).
     */
    public function eanCheckDigit(string $body): int
    {
        $digits = preg_replace('/\D+/', '', $body) ?? '';
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $n = (int)$digits[$len - 1 - $i];
            $sum += ($i % 2 === 0) ? $n : ($n * 3);
        }
        return (10 - ($sum % 10)) % 10;
    }

    public function barcodeExists(string $barcode, ?int $exceptProductId = null): bool
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return false;
        }
        $sql = "SELECT id FROM products WHERE barcode = ?";
        $params = [$barcode];
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
