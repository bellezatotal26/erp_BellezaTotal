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
        if ($alters) {
            try {
                $this->db->exec('ALTER TABLE sales ' . implode(', ', $alters));
            } catch (\Throwable $e) {
                error_log('SalesDocumentService: ' . $e->getMessage());
            }
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

    public static function resolveDueDate(string $terms, string $saleDate): ?string
    {
        $base = strtotime($saleDate) ?: time();
        return match ($terms) {
            'cash' => date('Y-m-d', $base),
            'net_15' => date('Y-m-d', strtotime('+15 days', $base)),
            'net_30' => date('Y-m-d', strtotime('+30 days', $base)),
            'overdue' => date('Y-m-d', strtotime('-1 day', $base)),
            default => date('Y-m-d', strtotime('+15 days', $base)),
        };
    }

    public static function label(string $documentType): string
    {
        return self::documentTypes()[$documentType] ?? 'Documento';
    }
}
