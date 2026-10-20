<?php

namespace SoftNova\Services;

/**
 * Asegura columnas de documentos de venta (tipo, fechas, condición de pago).
 */
class SalesDocumentService
{
    public function __construct(private \PDO $db)
    {
        $this->ensureColumns();
    }

    public function ensureColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $cols = [];
        try {
            foreach ($this->db->query('SHOW COLUMNS FROM sales')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string)$row['Field'])] = true;
            }
        } catch (\Throwable $e) {
            return;
        }

        $alters = [];
        if (!isset($cols['document_type'])) {
            $alters[] = "ADD COLUMN document_type VARCHAR(20) NOT NULL DEFAULT 'invoice' AFTER invoice_number";
        }
        if (!isset($cols['payment_terms'])) {
            $alters[] = "ADD COLUMN payment_terms VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER payment_method";
        }
        if (!isset($cols['due_date'])) {
            $alters[] = "ADD COLUMN due_date DATE NULL AFTER sale_date";
        }
        if (!isset($cols['received_date'])) {
            $alters[] = "ADD COLUMN received_date DATE NULL AFTER due_date";
        }
        // UUID del cliente para reintentos offline sin duplicar la venta.
        if (!isset($cols['client_sale_uuid'])) {
            $alters[] = "ADD COLUMN client_sale_uuid VARCHAR(64) NULL AFTER notes";
        }
        if (!isset($cols['delivery_fee'])) {
            $alters[] = "ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount";
        }
        if (!isset($cols['delivery_company_fee'])) {
            $alters[] = "ADD COLUMN delivery_company_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER delivery_fee";
        }
        if (!isset($cols['cash_received'])) {
            $alters[] = "ADD COLUMN cash_received DECIMAL(12,2) NULL DEFAULT NULL AFTER delivery_company_fee";
        }
        if (!isset($cols['cash_change'])) {
            $alters[] = "ADD COLUMN cash_change DECIMAL(12,2) NULL DEFAULT NULL AFTER cash_received";
        }
        if ($alters) {
            try {
                $this->db->exec('ALTER TABLE sales ' . implode(', ', $alters));
            } catch (\Throwable $e) {
                error_log('SalesDocumentService: ' . $e->getMessage());
            }
        }

        // Asegura ENUM con pending (crédito) + completed + cancelled.
        try {
            $statusCol = $this->db->query("SHOW COLUMNS FROM sales LIKE 'status'")->fetch(\PDO::FETCH_ASSOC);
            $type = strtolower((string)($statusCol['Type'] ?? ''));
            if ($type !== '' && (!str_contains($type, "'pending'") || !str_contains($type, "'cancelled'"))) {
                $this->db->exec(
                    "ALTER TABLE sales
                     MODIFY COLUMN status ENUM('completed','pending','cancelled') NOT NULL DEFAULT 'completed'"
                );
            }
        } catch (\Throwable $e) {
            error_log('SalesDocumentService status enum: ' . $e->getMessage());
        }

        try {
            // Corrige ventas con status vacío (ENUM inválido en installs viejas).
            $this->db->exec(
                "UPDATE sales
                 SET status = CASE
                    WHEN payment_status IN ('pending', 'partial') THEN 'pending'
                    ELSE 'completed'
                 END
                 WHERE status IS NULL OR status = ''"
            );
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $idx = $this->db->query(
                "SHOW INDEX FROM sales WHERE Key_name = 'uq_sales_client_uuid'"
            )->fetch();
            if (!$idx) {
                $this->db->exec('CREATE UNIQUE INDEX uq_sales_client_uuid ON sales (client_sale_uuid)');
            }
        } catch (\Throwable $e) {
            // silencioso: se reintenta en la próxima petición
        }
        $done = true;
    }

    /** @return array<string,string> */
    public static function documentTypes(): array
    {
        return [
            'invoice' => 'Factura interna',
            'remission' => 'Remisión',
            'collection' => 'Cuenta de cobro',
            'electronic' => 'Factura electrónica',
        ];
    }

    /** @return array<string,string> */
    public static function paymentTerms(): array
    {
        return [
            'cash' => 'Contado / pago inmediato',
            'net_15' => 'Crédito 15 días',
            'net_30' => 'Crédito 30 días',
            'net_60' => 'Crédito 60 días',
            'net_90' => 'Crédito 90 días',
            'net_120' => 'Crédito 120 días',
            'overdue' => 'Pago ya vencido',
        ];
    }

    public static function prefixFor(string $documentType): string
    {
        return match ($documentType) {
            'remission' => 'REM-',
            'collection' => 'CCO-',
            'electronic' => 'FE-',
            default => 'FAC-',
        };
    }

    /** Días de un plazo personalizado "net_N" (1..3650) o null si no aplica. */
    public static function customTermDays(string $terms): ?int
    {
        if (!preg_match('/^net_(\d{1,4})$/', $terms, $m)) {
            return null;
        }
        $days = (int)$m[1];
        return ($days >= 1 && $days <= 3650) ? $days : null;
    }

    public static function isValidTerms(string $terms): bool
    {
        return isset(self::paymentTerms()[$terms]) || self::customTermDays($terms) !== null;
    }

    public static function termDays(string $terms): int
    {
        $custom = self::customTermDays($terms);
        if ($custom !== null) {
            return $custom;
        }
        return match ($terms) {
            'cash' => 0,
            'net_15' => 15,
            'net_30' => 30,
            'net_60' => 60,
            'net_90' => 90,
            'net_120' => 120,
            'overdue' => 0,
            default => 30,
        };
    }

    public static function resolveDueDate(string $terms, string $saleDate): ?string
    {
        $base = strtotime($saleDate) ?: time();
        $custom = self::customTermDays($terms);
        if ($custom !== null) {
            return date('Y-m-d', strtotime('+' . $custom . ' days', $base));
        }
        return match ($terms) {
            'cash' => date('Y-m-d', $base),
            'net_15' => date('Y-m-d', strtotime('+15 days', $base)),
            'net_30' => date('Y-m-d', strtotime('+30 days', $base)),
            'net_60' => date('Y-m-d', strtotime('+60 days', $base)),
            'net_90' => date('Y-m-d', strtotime('+90 days', $base)),
            'net_120' => date('Y-m-d', strtotime('+120 days', $base)),
            'overdue' => date('Y-m-d', strtotime('-1 day', $base)),
            default => date('Y-m-d', strtotime('+15 days', $base)),
        };
    }

    public static function label(string $documentType): string
    {
        return self::documentTypes()[$documentType] ?? 'Documento';
    }

    /**
     * Observación humana de la venta (sin metadatos de cobro/sistema).
     * Sirve para listados, detalle y reporte de caja.
     */
    public static function displayNotes(string $notes): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }
        $parts = preg_split('/\s*[·|]\s*/u', $notes) ?: [];
        $keep = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match(
                '/^(venta\s*r[aá]pida\s*desde\s*caja|offline|combo\s*:|mixto\s*:|recibido(?:\s+efectivo)?\s*:|cambio\s*:|vueltas\s*:|pago\s*mixto|domicilio\s*:|venta\s+al\s+por\s+mayor|descuento|recargo\s+cr[eé]dito|abono\s+inicial)/iu',
                $part
            )) {
                continue;
            }
            if (preg_match('/^(medio|efectivo|transferencia|dat[aá]fono|tarjeta|nequi)\b/iu', $part)) {
                continue;
            }
            if (preg_match('/^\d+[\d.,]*\s*$/', $part)) {
                continue;
            }
            $keep[] = $part;
        }
        return trim(implode(' · ', $keep));
    }
}
