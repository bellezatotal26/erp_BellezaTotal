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
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $this->stock->decrease(
                $productId,
                $quantity,
                'sale',
                $saleId,
                $notesPrefix . ': ' . $invoiceNumber
            );
        }
    }
    
    /**
     * Devolver stock y revertir caja al cancelar
     */
    public function cancelSale(int $saleId): array
    {
        $sale = $this->query(
            "SELECT * FROM sales WHERE id = ? AND status IN ('completed', 'pending')",
            [$saleId]
        )->fetch();
        
        if (!$sale) {
            throw new \RuntimeException('Venta no encontrada o ya cancelada');
        }
        
        $this->db->beginTransaction();
        try {
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
            
            $this->query(
                "UPDATE sales SET status = 'cancelled',
                    notes = CONCAT(IFNULL(notes, ''), ' | CANCELADO: ', ?)
                 WHERE id = ?",
                [date('Y-m-d H:i'), $saleId]
            );
            
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        
        $reversed = $this->cash->reverseSaleMovements($saleId, $sale['invoice_number']);
        
        return [
            'sale' => $sale,
            'reversed_cash' => $reversed,
        ];
    }
}
