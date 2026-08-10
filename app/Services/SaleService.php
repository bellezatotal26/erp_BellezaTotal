<?php

namespace SoftNova\Services;

/**
 * Servicio de ventas: creación, abonos y cancelación
 */
class SaleService
{
    private \PDO $db;
    private StockService $stock;
    private CashService $cash;
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->stock = new StockService($db);
        $this->cash = new CashService($db);
    }
    
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function stock(): StockService
    {
        return $this->stock;
    }
    
    public function cash(): CashService
    {
        return $this->cash;
    }
    
    /**
     * Descontar stock de ítems de venta (valida rowCount)
     */
    public function decreaseItemsStock(array $items, int $saleId, string $invoiceNumber, string $notesPrefix = 'Venta'): void
    {
        try {
            (new ProductOfferService($this->db))->expireDueOffers();
        } catch (\Throwable $e) {
            // no bloquear venta
        }
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $oversell = null;
            if (!empty($item['stock_oversell_reason'])) {
                $oversell = [
                    'reason' => (string)$item['stock_oversell_reason'],
                    'note' => (string)($item['stock_oversell_note'] ?? ''),
                    'sale_item_id' => isset($item['sale_item_id']) ? (int)$item['sale_item_id'] : null,
                ];
            }
            $this->stock->decrease(
                $productId,
                $quantity,
                'sale',
                $saleId,
                $notesPrefix . ': ' . $invoiceNumber,
                isset($item['unit_cost']) ? (float)$item['unit_cost'] : null,
                $oversell
            );
        }
    }
    
    /**
     * Devolver stock y revertir caja al cancelar.
     * @param string $reasonLabel Motivo legible (obligatorio)
     * @param string $reasonCode  Código interno opcional
     */
    public function cancelSale(int $saleId, string $reasonLabel = '', string $reasonCode = ''): array
    {
        if ($saleId <= 0) {
            throw new \InvalidArgumentException('ID de venta inválido');
        }

        // Cualquier estado distinto de cancelado es anulable.
        // (Instalaciones viejas pueden tener status vacío o sin ENUM 'pending'.)
        $reasonLabel = trim(preg_replace('/\s+/', ' ', $reasonLabel) ?? '');
        if ($reasonLabel === '') {
            throw new \InvalidArgumentException('Debe indicar el motivo de la eliminación');
        }
        if (mb_strlen($reasonLabel) > 180) {
            $reasonLabel = mb_substr($reasonLabel, 0, 180);
        }

        // DDL fuera de la transacción (CREATE/ALTER en MySQL hace COMMIT implícito).
        try {
            $this->stock->lots();
        } catch (\Throwable $e) {
            // ignore
        }
        
        $this->db->beginTransaction();
        try {
            try {
                $sale = $this->query(
                    "SELECT * FROM sales WHERE id = ? LIMIT 1 FOR UPDATE",
                    [$saleId]
                )->fetch();
            } catch (\Throwable $e) {
                $sale = $this->query(
                    "SELECT * FROM sales WHERE id = ? LIMIT 1",
                    [$saleId]
                )->fetch();
            }

            if (!$sale) {
                throw new \RuntimeException('Venta no encontrada');
            }

            $status = strtolower(trim((string)($sale['status'] ?? '')));
            if ($status === 'cancelled') {
                throw new \RuntimeException('La venta ya está cancelada');
            }

            // Anular deudas de sobreventa antes de devolver stock (evitar cubrir otras deudas).
            $this->stock->cancelDebtsForSale($saleId);

            $items = $this->query("SELECT * FROM sale_items WHERE sale_id = ?", [$saleId])->fetchAll();
            foreach ($items as $item) {
                if (empty($item['product_id'])) {
                    continue;
                }
                $this->stock->increase(
                    (int)$item['product_id'],
                    (int)$item['quantity'],
                    'return',
                    $saleId,
                    'Devolucion: ' . $sale['invoice_number']
                );
            }

            $noteSuffix = ' | CANCELADO: ' . date('Y-m-d H:i') . ' · Motivo: ' . $reasonLabel;
            if ($reasonCode !== '') {
                $noteSuffix .= ' [' . $reasonCode . ']';
            }

            // cancel_reason puede no existir aún en installs viejas; el schema lo crea al usar CustomOptionService.
            try {
                $upd = $this->query(
                    "UPDATE sales SET status = 'cancelled', cancel_reason = ?,
                        notes = CONCAT(IFNULL(notes, ''), ?)
                     WHERE id = ? AND (status IS NULL OR status = '' OR status <> 'cancelled')",
                    [$reasonLabel, $noteSuffix, $saleId]
                );
            } catch (\Throwable $e) {
                $upd = $this->query(
                    "UPDATE sales SET status = 'cancelled',
                        notes = CONCAT(IFNULL(notes, ''), ?)
                     WHERE id = ? AND (status IS NULL OR status = '' OR status <> 'cancelled')",
                    [$noteSuffix, $saleId]
                );
            }
            if ($upd->rowCount() < 1) {
                throw new \RuntimeException('La venta ya está cancelada');
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        
        $reversed = $this->cash->reverseSaleMovements($saleId, $sale['invoice_number']);
        
        return [
            'sale' => $sale,
            'reversed_cash' => $reversed,
            'cancel_reason' => $reasonLabel,
        ];
    }
}
