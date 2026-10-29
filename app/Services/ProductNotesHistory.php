<?php

namespace SoftNova\Services;

/**
 * Historial append-only de notas internas del producto
 * (fecha + usuario responsable). products.notes queda como última nota (vista rápida).
 */
final class ProductNotesHistory
{
    private static bool $ready = false;
    private static bool $backfilled = false;

    /** Crea la tabla si falta. No ejecuta DDL dentro de una transacción. */
    public static function ensure(\PDO $db): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            $st = $db->query("SHOW TABLES LIKE 'product_notes'");
            if ($st && $st->fetchColumn()) {
                self::$ready = true;
                return true;
            }
            if ($db->inTransaction()) {
                return false;
            }
            $db->exec(
                "CREATE TABLE IF NOT EXISTS product_notes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    note TEXT NOT NULL,
                    user_id INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_pn_product (product_id, id),
                    INDEX idx_pn_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$ready = true;
        } catch (\Throwable $e) {
            error_log('product_notes ensure: ' . $e->getMessage());
        }
        return self::$ready;
    }

    /**
     * Migra una sola vez products.notes → primera entrada del historial
     * cuando aún no hay filas para ese producto.
     */
    public static function backfillFromLegacy(\PDO $db): void
    {
        if (self::$backfilled || !self::ensure($db) || $db->inTransaction()) {
            return;
        }
        self::$backfilled = true;

        // Corrección puntual (sin UI de edición): tipografía "10 OK INVENT 19/10" → "1 OK…".
        try {
            $db->exec(
                "UPDATE products
                 SET notes = REPLACE(notes, '10 OK INVENT 19/10', '1 OK INVENT 19/10')
                 WHERE notes LIKE '%10 OK INVENT 19/10%'"
            );
            $db->exec(
                "UPDATE product_notes
                 SET note = REPLACE(note, '10 OK INVENT 19/10', '1 OK INVENT 19/10')
                 WHERE note LIKE '%10 OK INVENT 19/10%'"
            );
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $db->exec(
                "INSERT INTO product_notes (product_id, note, user_id, created_at)
                 SELECT p.id, p.notes, p.created_by,
                        COALESCE(p.updated_at, p.created_at, NOW())
                 FROM products p
                 LEFT JOIN product_notes n ON n.product_id = p.id
                 WHERE p.notes IS NOT NULL AND TRIM(p.notes) <> ''
                   AND n.id IS NULL"
            );
        } catch (\Throwable $e) {
            // Sin created_by / updated_at en installs viejos.
            try {
                $db->exec(
                    "INSERT INTO product_notes (product_id, note, user_id, created_at)
                     SELECT p.id, p.notes, NULL, COALESCE(p.created_at, NOW())
                     FROM products p
                     LEFT JOIN product_notes n ON n.product_id = p.id
                     WHERE p.notes IS NOT NULL AND TRIM(p.notes) <> ''
                       AND n.id IS NULL"
                );
            } catch (\Throwable $e2) {
                error_log('product_notes backfill: ' . $e2->getMessage());
            }
        }
    }

    public static function add(\PDO $db, int $productId, string $note, ?int $userId = null): ?int
    {
        $note = trim($note);
        if ($productId <= 0 || $note === '' || !self::ensure($db)) {
            return null;
        }
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 4000);
        } else {
            $note = substr($note, 0, 4000);
        }
        $uid = $userId ?? (isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : null);
        if ($uid !== null && $uid <= 0) {
            $uid = null;
        }
        try {
            $db->prepare(
                'INSERT INTO product_notes (product_id, note, user_id) VALUES (?, ?, ?)'
            )->execute([$productId, $note, $uid]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('product_notes add: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return list<array{id:int,note:string,user_id:?int,user_name:?string,created_at:string}>
     */
    public static function listForProduct(\PDO $db, int $productId, int $limit = 100): array
    {
        if ($productId <= 0 || !self::ensure($db)) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $db->prepare(
                "SELECT n.id, n.note, n.user_id, n.created_at, u.name AS user_name
                 FROM product_notes n
                 LEFT JOIN users u ON u.id = n.user_id
                 WHERE n.product_id = ?
                 ORDER BY n.id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([$productId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return array_map(static function (array $r): array {
                return [
                    'id' => (int)$r['id'],
                    'note' => (string)$r['note'],
                    'user_id' => isset($r['user_id']) ? (int)$r['user_id'] : null,
                    'user_name' => $r['user_name'] !== null && $r['user_name'] !== ''
                        ? (string)$r['user_name']
                        : null,
                    'created_at' => (string)($r['created_at'] ?? ''),
                ];
            }, $rows);
        } catch (\Throwable $e) {
            error_log('product_notes list: ' . $e->getMessage());
            return [];
        }
    }
}
