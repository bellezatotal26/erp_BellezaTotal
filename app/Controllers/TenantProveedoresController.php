<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\TenantAuditService;

class TenantProveedoresController extends TenantController
{
    /** Relaciones que se pueden trasladar a otro proveedor al fusionar/eliminar. */
    private const REL_LABELS = [
        'orders' => 'OC / facturas de compra',
        'payments' => 'Abonos a proveedor',
        'credit_notes' => 'Notas crédito de compra',
        'imports' => 'Facturas leídas (borradores)',
        'products' => 'Productos vinculados',
        'lots' => 'Lotes de inventario',
        'expenses' => 'Gastos',
        'accounting' => 'Líneas de asientos contables',
        'consignors' => 'Vínculo de consignación',
    ];
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('proveedores');
    }
    
    public function index(): void
    {
        $action = $this->request->get('action');
        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('proveedores', 'create');
            $this->create();
            return;
        }
        if ($action === 'edit' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('proveedores', 'edit');
            $this->edit();
            return;
        }
        if ($action === 'delete' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('proveedores', 'delete');
            $this->delete();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('proveedores', 'export');
            $this->exportSuppliers();
            return;
        }
        
        $q = trim((string)$this->request->get('q', ''));
        $documentType = trim((string)$this->request->get('document_type', ''));
        $ally = trim((string)$this->request->get('ally', ''));
        $allowedDocs = ['CC', 'CE', 'NIT', 'PPT', 'OTROS', 'RUT'];
        if ($documentType !== '' && !in_array($documentType, $allowedDocs, true)) {
            $documentType = '';
        }
        if (!in_array($ally, ['1', '0'], true)) {
            $ally = '';
        }

        $where = ["status = 'active'"];
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(name LIKE ? OR contact_name LIKE ? OR document_number LIKE ?
                OR email LIKE ? OR phone LIKE ? OR address LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($documentType !== '') {
            $where[] = 'document_type = ?';
            $params[] = $documentType;
        }
        if ($ally === '1') {
            $where[] = 'COALESCE(is_ally, 0) = 1';
        } elseif ($ally === '0') {
            $where[] = 'COALESCE(is_ally, 0) = 0';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $per = $this->resolvePerPage();
        $total = (int)$this->query("SELECT COUNT(*) as c FROM suppliers {$whereSql}", $params)->fetch()['c'];
        $pagination = $this->paginate($total, $per['perPage']);
        
        $suppliers = $this->query(
            "SELECT * FROM suppliers {$whereSql} ORDER BY name ASC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
            $params
        )->fetchAll();

        // Relaciones por proveedor de la página (para el aviso al eliminar/fusionar)
        // y catálogo completo de destinos posibles para trasladarlas.
        $relBySupplier = $this->relationCountsFor(array_map(static fn($s) => (int)$s['id'], $suppliers));
        foreach ($suppliers as &$sRow) {
            $sRow['relations'] = $relBySupplier[(int)$sRow['id']] ?? [];
        }
        unset($sRow);
        try {
            $supplierOptions = $this->query(
                "SELECT id, name, document_type, document_number FROM suppliers
                 WHERE status = 'active' ORDER BY name ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
            $supplierOptions = [];
        }

        $this->view('tenant.proveedores', $this->tenantViewData([
            'suppliers' => $suppliers,
            'supplierOptions' => $supplierOptions,
            'relationLabels' => self::REL_LABELS,
            'pagination' => $pagination,
            'perPageOptions' => $per['options'],
            'qFilter' => $q,
            'documentTypeFilter' => $documentType,
            'allyFilter' => $ally,
            'listQuery' => array_filter([
                'q' => $q !== '' ? $q : null,
                'document_type' => $documentType !== '' ? $documentType : null,
                'ally' => $ally !== '' ? $ally : null,
                'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
            ], static fn($v) => $v !== null && $v !== ''),
        ]));
    }
    
    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/proveedores')) return;
        
        $name = trim($this->request->post('name') ?? '');
        if (empty($name)) { $this->respond(false, 'El nombre de la empresa es requerido', '/app/proveedores'); return; }
        
        $documentNumber = trim((string)$this->request->post('document_number'));
        $phone = trim((string)$this->request->post('phone'));
        if ($documentNumber === '' || $phone === '') {
            $this->respond(false, 'El número de documento y el teléfono son obligatorios', '/app/proveedores');
            return;
        }
        
        $image = $this->uploadImage();
        // Asegurar columnas aliado
        try {
            $cols = [];
            foreach ($this->db->query('SHOW COLUMNS FROM suppliers')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            $alters = [];
            if (!isset($cols['is_ally'])) $alters[] = 'ADD COLUMN is_ally TINYINT(1) NOT NULL DEFAULT 0';
            if (!isset($cols['discount_percent'])) $alters[] = 'ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0';
            if ($alters) $this->db->exec('ALTER TABLE suppliers ' . implode(', ', $alters));
        } catch (\Throwable $e) {}

        $isAlly = $this->request->post('is_ally') === '1' ? 1 : 0;
        $discountPct = max(0, min(100, (float)$this->request->post('discount_percent', 0)));
        
        $this->query("INSERT INTO suppliers (name, document_type, document_number, contact_name, email, phone, address, notes, image, is_ally, discount_percent) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$name, $this->request->post('document_type','NIT'), $documentNumber, $this->request->post('contact_name'), $this->request->post('email'), $phone, $this->request->post('address'), $this->request->post('notes'), $image, $isAlly, $discountPct]);
        
        $this->respond(true, 'Proveedor creado: ' . $name, '/app/proveedores');
    }
    
    private function edit(): void
    {
        if (!$this->validateCsrfOrFail('/app/proveedores')) return;
        $id = (int)$this->request->post('id');
        $name = trim($this->request->post('name') ?? '');
        if (empty($name)) { $this->respond(false, 'El nombre de la empresa es requerido', '/app/proveedores'); return; }
        
        $documentNumber = trim((string)$this->request->post('document_number'));
        $phone = trim((string)$this->request->post('phone'));
        if ($documentNumber === '' || $phone === '') {
            $this->respond(false, 'El número de documento y el teléfono son obligatorios', '/app/proveedores');
            return;
        }
        
        $image = $this->uploadImage();
        $isAlly = $this->request->post('is_ally') === '1' ? 1 : 0;
        $discountPct = max(0, min(100, (float)$this->request->post('discount_percent', 0)));
        $sql = "UPDATE suppliers SET name=?, document_type=?, document_number=?, contact_name=?, email=?, phone=?, address=?, notes=?, is_ally=?, discount_percent=?";
        $params = [$name, $this->request->post('document_type','NIT'), $documentNumber, $this->request->post('contact_name'), $this->request->post('email'), $phone, $this->request->post('address'), $this->request->post('notes'), $isAlly, $discountPct];
        
        if ($image) {
            $sql .= ", image=?";
            $params[] = $image;
        }
        $sql .= " WHERE id=?";
        $params[] = $id;
        
        $this->query($sql, $params);
        $this->respond(true, 'Proveedor actualizado', '/app/proveedores');
    }
    
    private function uploadImage(): ?string
    {
        if (empty($_FILES['image']['tmp_name'])) {
            return null;
        }
        if (!is_uploaded_file($_FILES['image']['tmp_name'])) {
            return null;
        }
        if (($_FILES['image']['size'] ?? 0) > 2097152) {
            return null;
        }
        
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['image']['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            return null;
        }
        
        $uploadDir = PUBLIC_PATH . '/uploads/suppliers/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'sup_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $dest = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            return 'uploads/suppliers/' . $filename;
        }
        return null;
    }
    
    /**
     * Conteo de relaciones por proveedor (solo claves con registros).
     *
     * @param list<int> $supplierIds
     * @return array<int,array<string,int>>
     */
    private function relationCountsFor(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_filter(array_map('intval', $supplierIds))));
        if ($supplierIds === []) {
            return [];
        }
        $in = implode(',', $supplierIds);
        $specs = [
            'orders' => "SELECT supplier_id AS k, COUNT(*) AS c FROM purchase_orders WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'payments' => "SELECT supplier_id AS k, COUNT(*) AS c FROM supplier_payments WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'credit_notes' => "SELECT supplier_id AS k, COUNT(*) AS c FROM purchase_credit_notes WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'imports' => "SELECT supplier_id AS k, COUNT(*) AS c FROM purchase_invoice_imports WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'products' => "SELECT supplier_id AS k, COUNT(*) AS c FROM product_suppliers WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'lots' => "SELECT supplier_id AS k, COUNT(*) AS c FROM product_lots WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'expenses' => "SELECT supplier_id AS k, COUNT(*) AS c FROM expenses WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
            'accounting' => "SELECT third_party_id AS k, COUNT(*) AS c FROM accounting_lines WHERE third_party_type = 'supplier' AND third_party_id IN ({$in}) GROUP BY third_party_id",
            'consignors' => "SELECT supplier_id AS k, COUNT(*) AS c FROM consignors WHERE supplier_id IN ({$in}) GROUP BY supplier_id",
        ];
        $out = [];
        foreach ($specs as $key => $sql) {
            try {
                foreach ($this->query($sql)->fetchAll() as $row) {
                    $sid = (int)($row['k'] ?? 0);
                    $c = (int)($row['c'] ?? 0);
                    if ($sid > 0 && $c > 0) {
                        $out[$sid][$key] = $c;
                    }
                }
            } catch (\Throwable $e) {
                // Tabla inexistente en esta instalación: se omite.
            }
        }
        return $out;
    }

    /** Texto corto: «3 OC / facturas de compra, 2 Abonos a proveedor». */
    private function relationsText(array $counts): string
    {
        $parts = [];
        foreach (self::REL_LABELS as $key => $label) {
            $c = (int)($counts[$key] ?? 0);
            if ($c > 0) {
                $parts[] = $c . ' ' . $label;
            }
        }
        return implode(', ', $parts);
    }

    private function delete(): void
    {
        if (!$this->validateCsrfOrFail('/app/proveedores')) {
            return;
        }
        $id = (int)$this->request->post('id');
        $targetId = (int)$this->request->post('target_supplier_id', 0);
        if ($id <= 0) {
            $this->respond(false, 'Proveedor invalido', '/app/proveedores');
            return;
        }

        $source = $this->query(
            "SELECT id, name, status FROM suppliers WHERE id = ?",
            [$id]
        )->fetch();
        if (!$source || ($source['status'] ?? '') !== 'active') {
            $this->respond(false, 'El proveedor no existe o ya fue eliminado', '/app/proveedores');
            return;
        }

        $counts = $this->relationCountsFor([$id])[$id] ?? [];
        $total = array_sum($counts);

        // Con relaciones y sin destino no se elimina: quedarían huérfanas.
        if ($total > 0 && $targetId <= 0) {
            $this->respond(
                false,
                '«' . $source['name'] . '» tiene relaciones en el sistema (' . $this->relationsText($counts)
                    . '). Elija el proveedor destino para trasladarlas y luego eliminarlo.',
                '/app/proveedores'
            );
            return;
        }

        if ($targetId > 0) {
            if ($targetId === $id) {
                $this->respond(false, 'El proveedor destino debe ser otro distinto al que se elimina', '/app/proveedores');
                return;
            }
            $target = $this->query(
                "SELECT id, name, status FROM suppliers WHERE id = ?",
                [$targetId]
            )->fetch();
            if (!$target || ($target['status'] ?? '') !== 'active') {
                $this->respond(false, 'El proveedor destino no existe o está inactivo', '/app/proveedores');
                return;
            }

            try {
                $moved = $this->mergeSupplierRelations($id, $targetId, (string)$target['name'], $counts);
            } catch (\Throwable $e) {
                $this->respond(false, 'No se pudieron trasladar las relaciones: ' . $e->getMessage(), '/app/proveedores');
                return;
            }

            TenantAuditService::safeLog(
                $this->db,
                'delete',
                'proveedores',
                'Proveedor fusionado y eliminado: «' . $source['name'] . '» (#' . $id . ') → «'
                    . $target['name'] . '» (#' . $targetId . ') · ' . $this->relationsText($counts),
                'supplier',
                $id,
                ['target_supplier_id' => $targetId, 'relations' => $counts, 'moved' => $moved]
            );

            $this->respond(
                true,
                'Proveedor «' . $source['name'] . '» eliminado. Se trasladaron ' . $moved
                    . ' relación(es) a «' . $target['name'] . '» (' . $this->relationsText($counts) . ').',
                '/app/proveedores'
            );
            return;
        }

        $stmt = $this->query("UPDATE suppliers SET status = 'inactive' WHERE id = ?", [$id]);
        if ($stmt->rowCount() < 1) {
            $this->respond(false, 'No se pudo eliminar el proveedor', '/app/proveedores');
            return;
        }

        TenantAuditService::safeLog(
            $this->db,
            'delete',
            'proveedores',
            'Proveedor eliminado sin relaciones: «' . $source['name'] . '» (#' . $id . ')',
            'supplier',
            $id,
            []
        );

        $this->respond(true, 'Proveedor eliminado', '/app/proveedores');
    }

    /**
     * Traslada TODAS las relaciones del proveedor origen al destino y da de
     * baja el origen (baja lógica, como el resto del sistema). En transacción:
     * o se mueve todo o no se mueve nada.
     *
     * @param array<string,int> $counts Conteo previo (solo informativo)
     * @return int Total de filas actualizadas
     */
    private function mergeSupplierRelations(int $sourceId, int $targetId, string $targetName, array $counts): int
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        $moved = 0;
        try {
            $simpleTables = [
                'purchase_orders',
                'supplier_payments',
                'purchase_credit_notes',
                'purchase_invoice_imports',
                'product_lots',
                'expenses',
            ];
            foreach ($simpleTables as $table) {
                try {
                    $stmt = $this->query(
                        "UPDATE {$table} SET supplier_id = ? WHERE supplier_id = ?",
                        [$targetId, $sourceId]
                    );
                    $moved += $stmt->rowCount();
                } catch (\Throwable $e) {
                    // Tabla inexistente en esta instalación: se omite.
                }
            }

            // Productos vinculados: UNIQUE(product_id, supplier_id). Si el destino
            // ya tiene el producto, se conserva el vínculo del destino.
            try {
                $stmt = $this->query(
                    "DELETE FROM product_suppliers
                     WHERE supplier_id = ?
                       AND product_id IN (
                           SELECT product_id FROM (
                               SELECT product_id FROM product_suppliers WHERE supplier_id = ?
                           ) dup
                       )",
                    [$sourceId, $targetId]
                );
                $moved += $stmt->rowCount();
                $stmt = $this->query(
                    "UPDATE product_suppliers SET supplier_id = ? WHERE supplier_id = ?",
                    [$targetId, $sourceId]
                );
                $moved += $stmt->rowCount();
            } catch (\Throwable $e) {
                // Tabla inexistente: se omite.
            }

            // Tercero en asientos contables ya emitidos (duplicado = misma empresa).
            try {
                $stmt = $this->query(
                    "UPDATE accounting_lines
                     SET third_party_id = ?, third_party_name = ?
                     WHERE third_party_type = 'supplier' AND third_party_id = ?",
                    [$targetId, $targetName, $sourceId]
                );
                $moved += $stmt->rowCount();
            } catch (\Throwable $e) {
                // Sin contabilidad: se omite.
            }

            // Consignación es 1 a 1 con el proveedor: solo se mueve si el destino no tiene una.
            try {
                $hasTarget = (int)$this->query(
                    "SELECT COUNT(*) FROM consignors WHERE supplier_id = ?",
                    [$targetId]
                )->fetchColumn();
                if ($hasTarget === 0) {
                    $stmt = $this->query(
                        "UPDATE consignors SET supplier_id = ? WHERE supplier_id = ?",
                        [$targetId, $sourceId]
                    );
                    $moved += $stmt->rowCount();
                }
            } catch (\Throwable $e) {
                // Sin módulo de consignación: se omite.
            }

            $stmt = $this->query(
                "UPDATE suppliers SET status = 'inactive' WHERE id = ? AND status = 'active'",
                [$sourceId]
            );
            if ($stmt->rowCount() < 1) {
                throw new \RuntimeException('El proveedor ya no estaba activo');
            }

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $moved;
    }
    
    private function exportSuppliers(): void
    {
        $q = trim((string)$this->request->get('q', ''));
        $documentType = trim((string)$this->request->get('document_type', ''));
        $ally = trim((string)$this->request->get('ally', ''));
        $allowedDocs = ['CC', 'CE', 'NIT', 'PPT', 'OTROS', 'RUT'];
        if ($documentType !== '' && !in_array($documentType, $allowedDocs, true)) {
            $documentType = '';
        }
        if (!in_array($ally, ['1', '0'], true)) {
            $ally = '';
        }

        $where = ["status = 'active'"];
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(name LIKE ? OR contact_name LIKE ? OR document_number LIKE ?
                OR email LIKE ? OR phone LIKE ? OR address LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($documentType !== '') {
            $where[] = 'document_type = ?';
            $params[] = $documentType;
        }
        if ($ally === '1') {
            $where[] = 'COALESCE(is_ally, 0) = 1';
        } elseif ($ally === '0') {
            $where[] = 'COALESCE(is_ally, 0) = 0';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->query(
            "SELECT name, document_type, document_number, contact_name, email, phone,
                    address, COALESCE(is_ally, 0) AS is_ally, COALESCE(discount_percent, 0) AS discount_percent
             FROM suppliers {$whereSql} ORDER BY name",
            $params
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['name'],
                $r['document_type'],
                $r['document_number'],
                $r['contact_name'],
                $r['email'],
                $r['phone'],
                $r['address'] ?? '',
                (int)$r['is_ally'] === 1 ? 'Si' : 'No',
                $r['discount_percent'],
            ];
        }
        
        $this->exportCsv(
            'proveedores_' . date('Ymd_His') . '.csv',
            ['Nombre', 'Tipo Doc', 'Documento', 'Contacto', 'Email', 'Telefono', 'Direccion', 'Aliado', 'Descuento %'],
            $csvRows
        );
    }
}
