<?php
$layout = 'tenant';
$title = 'Gastos - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gastos';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$expenses = $expenses ?? [];
$suppliers = $suppliers ?? [];
$operationalCats = $operationalCats ?? [];
$financialCats = $financialCats ?? [];
$administrativeCats = $administrativeCats ?? [];
$managementCats = $managementCats ?? [];
$customSubgroupSections = $customSubgroupSections ?? [];
$monthFinancial = $monthFinancial ?? 0;
$monthManagement = $monthManagement ?? 0;
$monthAccounting = $monthAccounting ?? 0;
$monthTotal = $monthTotal ?? 0;
$monthOperational = $monthOperational ?? 0;
$monthAdministrative = $monthAdministrative ?? 0;
$kindFilter = $kindFilter ?? '';
$qFilter = $qFilter ?? '';
$fromFilter = $fromFilter ?? '';
$toFilter = $toFilter ?? '';
$paymentFilter = $paymentFilter ?? '';
$supplierFilter = (int)($supplierFilter ?? 0);
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$pagination = $pagination ?? null;
$perPageOptions = $perPageOptions ?? [20, 50, 100, 200];
$listQuery = $listQuery ?? [];
$sortFilters = $sortFilters ?? [
    'sort' => '',
    'dir' => 'desc',
    'query' => $listQuery,
];
$paymentMethods = $paymentMethods ?? \SoftNova\Services\PaymentMethodCatalog::all();

function fmtG(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}

$canCreateExpense = \SoftNova\Core\TenantMiddleware::canDo('create', 'gastos');
$canEditExpense = \SoftNova\Core\TenantMiddleware::canDo('edit', 'gastos');
$canDeleteExpense = \SoftNova\Core\TenantMiddleware::canDo('delete', 'gastos');
$canExportExpense = \SoftNova\Core\TenantMiddleware::canDo('export', 'gastos');
$gastosBase = $viewInstance->route('app/gastos');
?>
<?php echo flashMessage(); ?>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Gastos mes</h4><div class="stat-value" style="color:var(--dmc-DC2626, #DC2626);"><?php echo fmtG((float)$monthTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Operativos (mes)</h4><div class="stat-value" style="color:var(--dmc-0D7C4A, #0D7C4A);"><?php echo fmtG((float)$monthOperational, $currency); ?></div><small>Descuentan caja</small></div>
    <div class="stat-card neumorphic stat-card--stack"><h4>Contables (mes)</h4><div class="stat-value"><?php echo fmtG((float)$monthAccounting, $currency); ?></div><small>Adm. <?php echo fmtG((float)$monthAdministrative, $currency); ?> · Fin. <?php echo fmtG((float)$monthFinancial, $currency); ?> · Gest. <?php echo fmtG((float)$monthManagement, $currency); ?></small></div>
</div>

<div style="display:flex;gap:15px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
    <?php if ($canCreateExpense): ?>
    <button onclick="resetExpenseForm(); document.getElementById('expenseModal').style.display='flex'" class="btn btn-primary neumorphic-btn" title="Nuevo gasto">Nuevo Gasto</button>
    <?php endif; ?>
    <?php if ($canExportExpense): ?>
    <a href="<?php echo $gastosBase; ?>?action=export<?php echo $listQuery !== [] ? '&' . http_build_query($listQuery) : ''; ?>" class="btn btn-secondary" title="Exporta el listado filtrado; sin filtros exporta todo">Exportar CSV</a>
    <?php endif; ?>
</div>

<form method="GET" action="<?php echo $gastosBase; ?>" class="inv-filters-form" style="margin-bottom:16px;">
    <?php if (!empty($sortFilters['sort'])): ?>
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars((string)$sortFilters['sort']); ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars((string)($sortFilters['dir'] ?? 'desc')); ?>">
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
            <label style="font-size:12px;">Buscar</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($qFilter); ?>" class="form-control" placeholder="Descripción, proveedor, recibo…">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Tipo</label>
            <select name="kind" class="form-control" style="width:auto;">
                <option value="">Todos los tipos</option>
                <option value="operational" <?php echo $kindFilter === 'operational' ? 'selected' : ''; ?>>Operativos</option>
                <option value="accounting" <?php echo $kindFilter === 'accounting' ? 'selected' : ''; ?>>Todos los contables</option>
                <option value="administrative" <?php echo $kindFilter === 'administrative' ? 'selected' : ''; ?>>Contables — Administrativos</option>
                <option value="financial" <?php echo $kindFilter === 'financial' ? 'selected' : ''; ?>>Contables — Financieros</option>
                <option value="management" <?php echo $kindFilter === 'management' ? 'selected' : ''; ?>>Contables — Gestión</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Desde</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($fromFilter); ?>" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Hasta</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($toFilter); ?>" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Método</label>
            <select name="payment_method" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <?php foreach ($paymentMethods as $code => $label): ?>
                    <option value="<?php echo htmlspecialchars((string)$code); ?>" <?php echo $paymentFilter === (string)$code ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(is_array($label) ? (string)($label['label'] ?? $code) : (string)$label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Proveedor</label>
            <select name="supplier_id" class="form-control" style="width:auto;max-width:200px;">
                <option value="0">Todos</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?php echo (int)$sup['id']; ?>" <?php echo $supplierFilter === (int)$sup['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$sup['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="per_page" value="<?php echo (int)($pagination['perPage'] ?? 20); ?>">
        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="<?php echo $gastosBase; ?>" class="btn btn-secondary">Limpiar</a>
        </div>
    </div>
</form>

<p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
    <strong>Operativos:</strong> papelería, alimentos, transporte, mantenimiento — <strong>descuentan caja</strong> si paga en efectivo.
    <strong>Contables:</strong> administrativos, financieros y de gestión — <strong>no descuentan caja</strong>, se contabilizan y quedan trazados por sub-grupo.
    Use <em>+ Agregar otro…</em> solo si es administrador (crea tipos permanentes en Configuración).
    <?php if (\SoftNova\Core\TenantMiddleware::isManagement()): ?>
        Administre el catálogo en <a href="<?php echo $viewInstance->route('app/configuracion'); ?>#gastos-admin">Configuración → Administración de gastos</a>.
    <?php endif; ?>
</p>

<div class="card neumorphic">
    <div class="card-header"><h3>Listado de Gastos</h3></div>
    <div class="card-body">
        <?php if (empty($expenses)): ?>
            <?php
            $hasFilters = $qFilter !== '' || $kindFilter !== '' || $fromFilter !== '' || $toFilter !== ''
                || $paymentFilter !== '' || $supplierFilter > 0;
            ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">
                <?php echo $hasFilters ? 'No hay gastos con esos filtros' : 'No hay gastos registrados'; ?>
            </p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Fecha', 'column' => 'date', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Usuario', 'column' => 'user', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Descripcion', 'column' => 'description', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Categoria', 'column' => 'category', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Proveedor', 'column' => 'supplier', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Monto', 'column' => 'amount', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Metodo', 'column' => 'method', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <th>Soporte</th>
                    <th><?php $viewInstance->partial('sortable_th', ['label' => 'Contabilidad', 'column' => 'entry', 'filters' => $sortFilters, 'baseUrl' => $gastosBase]); ?></th>
                    <?php if ($canEditExpense || $canDeleteExpense): ?><th>Acciones</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($expenses as $exp): ?>
                    <?php
                    $catLabel = $exp['category_label'] ?? ($exp['category'] ?? '');
                    if (!empty($exp['is_payroll']) && empty($exp['category_label'])) {
                        $catLabel = 'Nómina (liquidaciones)';
                    }
                    $ck = (string)($exp['category_kind'] ?? '');
                        $kindBadge = match ($ck) {
                            'operational' => 'badge-success',
                            'financial' => 'badge-warning',
                            'management' => 'badge-secondary',
                            default => \SoftNova\Services\ExpenseCategoryService::isAccountingKind($ck) ? 'badge-info' : 'badge-info',
                        };
                        $kindText = match ($ck) {
                            'operational' => 'Operativo',
                            'financial' => 'Contable · Financiero',
                            'management' => 'Contable · Gestión',
                            'administrative' => 'Contable · Administrativo',
                            default => str_starts_with($ck, \SoftNova\Services\ExpenseCategoryService::SUBGROUP_KIND_PREFIX)
                                ? 'Contable · Personalizado'
                                : '',
                        };
                    $methodLabel = \SoftNova\Services\PaymentMethodCatalog::label((string)($exp['payment_method'] ?? 'cash'));
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($exp['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars(trim((string)($exp['user_name'] ?? '')) !== '' ? (string)$exp['user_name'] : 'Sin usuario'); ?></td>
                        <td><?php echo htmlspecialchars($exp['description']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($catLabel); ?>
                            <?php if ($kindText): ?><br><span class="badge <?php echo $kindBadge; ?>" style="font-size:10px;"><?php echo $kindText; ?></span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($exp['supplier_name'] ?? '-'); ?></td>
                        <td style="font-weight:600;color:var(--dmc-DC2626, #DC2626);"><?php echo fmtG((float)$exp['amount'], $currency); ?></td>
                        <td><?php echo htmlspecialchars($methodLabel); ?></td>
                        <td>
                            <?php if (!empty($exp['receipt_path'])): ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo $gastosBase; ?>?action=receipt&id=<?php echo (int)$exp['id']; ?>" target="_blank" title="Ver foto/PDF">Ver</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($exp['is_payroll'])): ?>
                                <?php if (!empty($exp['payroll_run_id'])): ?>
                                <a class="btn btn-sm btn-info" href="<?php echo $viewInstance->route('app/nomina'); ?>?tab=runs&amp;action=run_detail&amp;id=<?php echo (int)$exp['payroll_run_id']; ?>" title="Asiento de nómina: se gestiona desde el módulo Nómina">Nómina</a>
                                <?php else: ?>
                                <span style="font-size:12px;color:var(--color-text-secondary);">Asiento de nómina</span>
                                <?php endif; ?>
                            <?php elseif (!empty($exp['is_bank'])): ?>
                                <a class="btn btn-sm btn-info" href="<?php echo $viewInstance->route('app/bancos'); ?>?tab=<?php echo str_starts_with(ltrim((string)($exp['notes'] ?? '')), 'bold_settlement:') ? 'bold' : 'conciliacion'; ?>" title="Asiento del módulo Bancos: se gestiona desde Bancos">Bancos</a>
                            <?php elseif (!empty($exp['accounting_entry_id'])): ?>
                                <a class="btn btn-sm btn-info" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=entries&amp;entry_id=<?php echo (int)$exp['accounting_entry_id']; ?>" title="Ver comprobante contable">
                                    <?php echo htmlspecialchars((string)($exp['accounting_entry_number'] ?? 'Ver')); ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size:12px;color:var(--color-text-secondary);">Sin asiento</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEditExpense || $canDeleteExpense): ?>
                        <td class="table-actions">
                            <?php if (!empty($exp['is_payroll'])): ?>
                                <span style="font-size:12px;color:var(--color-text-secondary);" title="Para corregirlo use Nómina → Liquidaciones → «Anular pago»">Se gestiona en Nómina</span>
                            <?php elseif (!empty($exp['is_bank'])): ?>
                                <span style="font-size:12px;color:var(--color-text-secondary);" title="Para corregirlo anule el registro en Bancos">Se gestiona en Bancos</span>
                            <?php else: ?>
                            <?php if ($canEditExpense && empty($exp['accounting_entry_id'])): ?>
                                <button type="button" class="btn btn-sm btn-secondary" title="Editar"
                                    onclick='openEditExpense(<?php echo json_encode([
                                        "id" => (int)$exp["id"],
                                        "description" => (string)$exp["description"],
                                        "amount" => (float)$exp["amount"],
                                        "expense_date" => (string)$exp["expense_date"],
                                        "category_id" => (int)($exp["category_id"] ?? 0),
                                        "supplier_id" => (int)($exp["supplier_id"] ?? 0),
                                        "payment_method" => (string)($exp["payment_method"] ?? "cash"),
                                        "receipt_number" => (string)($exp["receipt_number"] ?? ""),
                                        "notes" => (string)($exp["notes"] ?? ""),
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>Editar</button>
                            <?php endif; ?>
                            <?php if ($canDeleteExpense): ?>
                            <form method="POST" action="<?php echo $gastosBase; ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$exp['id']; ?>">
                                <button type="submit" data-confirm="¿Anular este gasto? Quedará en historial para trazabilidad contable." class="btn btn-sm btn-danger" title="Anular">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
            <?php
            $paginationBaseUrl = $gastosBase;
            $paginationQuery = $listQuery;
            $paginationPerPageOptions = $perPageOptions;
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery', 'paginationPerPageOptions'));
            ?>
    </div>
</div>

<?php if ($canCreateExpense || $canEditExpense): ?>
<div id="expenseModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:600px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3 id="expenseModalTitle">Nuevo Gasto</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('expenseModal').style.display='none'" title="Cerrar">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/gastos'); ?>?action=create" data-ajax="true" enctype="multipart/form-data" id="expenseForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="expenseId" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label>Descripcion *</label>
                    <input type="text" name="description" class="form-control" required placeholder="Ej. Comisión datáfono marzo">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Monto *</label>
                        <input type="text" inputmode="decimal" name="amount" class="form-control money-input" data-money="1" required placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Categoria *</label>
                        <?php $viewInstance->partial('expense_category_select', [
                            'operationalCats' => $operationalCats,
                            'financialCats' => $financialCats,
                            'administrativeCats' => $administrativeCats,
                            'managementCats' => $managementCats,
                            'customSubgroupSections' => $customSubgroupSections,
                            'required' => true,
                            'allowCustomExpenseCategory' => \SoftNova\Core\TenantMiddleware::isManagement(),
                        ]); ?>
                    </div>
                    <div class="form-group">
                        <label>Medio de pago</label>
                        <select name="payment_method" class="form-control" data-custom-other="payment_method">
                            <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Proveedor</label>
                    <select name="supplier_id" class="form-control">
                        <option value="">Sin proveedor</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>N° comprobante</label>
                        <input type="text" name="receipt_number" class="form-control" placeholder="Referencia">
                    </div>
                    <div class="form-group">
                        <label>Foto / PDF del pago</label>
                        <input type="file" name="receipt_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notas</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group js-cash-explanation-block" style="display:none;padding:10px 12px;border:1px solid #F59E0B;border-radius:8px;background:rgba(245,158,11,.08);">
                    <label style="font-weight:600;">Explicación del pago en efectivo *</label>
                    <input type="text" name="cash_explanation" class="form-control" maxlength="255"
                           placeholder="Ej. Retiro cajero Bancolombia Calle 80 / efectivo de ahorros">
                    <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                        Solo para gastos <strong>contables</strong> pagados en efectivo: no descuenta el cajón de caja.
                        Los gastos operativos en efectivo sí descuentan caja automáticamente.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('expenseModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<script>
function parseExpenseNotes(notes) {
    var raw = String(notes || '');
    var m = raw.match(/^Efectivo:\s*([^|]+)(?:\s*\|\s*(.*))?$/);
    if (m) {
        return { cashExplanation: m[1].trim(), notes: (m[2] || '').trim() };
    }
    return { cashExplanation: '', notes: raw };
}
function resetExpenseForm() {
    var form = document.getElementById('expenseForm');
    if (!form) return;
    form.reset();
    form.action = '<?php echo $viewInstance->route('app/gastos'); ?>?action=create';
    document.getElementById('expenseModalTitle').textContent = 'Nuevo Gasto';
    document.getElementById('expenseId').value = '0';
    var dateEl = form.querySelector('[name="expense_date"]');
    if (dateEl) dateEl.value = '<?php echo date('Y-m-d'); ?>';
    if (typeof syncCashExplanation === 'function') syncCashExplanation();
}
function openEditExpense(row) {
    resetExpenseForm();
    var form = document.getElementById('expenseForm');
    if (!form) return;
    document.getElementById('expenseModalTitle').textContent = 'Editar gasto';
    document.getElementById('expenseId').value = String(row.id || 0);
    form.action = '<?php echo $viewInstance->route('app/gastos'); ?>?action=edit';
    form.querySelector('[name="description"]').value = row.description || '';
    var amt = form.querySelector('[name="amount"]');
    if (amt) amt.value = window.SeriMoney ? SeriMoney.format(row.amount || 0) : String(row.amount || 0);
    form.querySelector('[name="expense_date"]').value = (row.expense_date || '').substr(0, 10);
    var parsed = parseExpenseNotes(row.notes || '');
    var cat = form.querySelector('[name="category_id"]');
    if (cat) {
        cat.value = row.category_id ? String(row.category_id) : '';
        if (typeof cat.dispatchEvent === 'function') cat.dispatchEvent(new Event('change', { bubbles: true }));
    }
    var sup = form.querySelector('[name="supplier_id"]');
    if (sup) sup.value = row.supplier_id ? String(row.supplier_id) : '';
    var pay = form.querySelector('[name="payment_method"]');
    if (pay) {
        pay.value = row.payment_method || 'cash';
        if (typeof pay.dispatchEvent === 'function') pay.dispatchEvent(new Event('change', { bubbles: true }));
    }
    form.querySelector('[name="receipt_number"]').value = row.receipt_number || '';
    form.querySelector('[name="notes"]').value = parsed.notes;
    var cashExpl = form.querySelector('[name="cash_explanation"]');
    if (cashExpl) cashExpl.value = parsed.cashExplanation;
    document.getElementById('expenseModal').style.display = 'flex';
    if (typeof syncCashExplanation === 'function') syncCashExplanation();
}
(function () {
    var form = document.querySelector('#expenseModal form');
    if (!form || form.dataset.expenseCashBound === '1') return;
    form.dataset.expenseCashBound = '1';
    var payEl = form.querySelector('[name="payment_method"]');
    var catEl = form.querySelector('[name="category_id"]');
    var explBlock = form.querySelector('.js-cash-explanation-block');
    var explEl = form.querySelector('[name="cash_explanation"]');
    function isCashMethod() {
        if (!payEl) return false;
        var v = String(payEl.value || '').toLowerCase();
        return v === 'cash' || v === 'efectivo' || v === '';
    }
    function isAccountingCategory() {
        if (!catEl || catEl.selectedIndex < 0) return false;
        var opt = catEl.options[catEl.selectedIndex];
        if (opt.getAttribute('data-accounting') === '1') return true;
        var k = String(opt.getAttribute('data-kind') || '');
        return k === 'administrative' || k === 'financial' || k === 'management' || k.indexOf('sg_') === 0;
    }
    function syncCashExplanation() {
        var show = isCashMethod() && isAccountingCategory();
        if (explBlock) explBlock.style.display = show ? '' : 'none';
        if (explEl) {
            explEl.required = show;
            if (!show) explEl.value = '';
        }
    }
    window.syncCashExplanation = syncCashExplanation;
    if (payEl) payEl.addEventListener('change', syncCashExplanation);
    if (catEl) catEl.addEventListener('change', syncCashExplanation);
    syncCashExplanation();

    // Aportes y prestaciones ya se causan en Nómina: registrarlos aquí duplica el costo.
    var payrollRe = /(n[oó]mina|pila|seguridad\s+social|parafiscal|aportes?\s+(salud|pensi[oó]n|eps|arl)|\beps\b|\barl\b|caja\s+de\s+compensaci[oó]n|\bsena\b|\bicbf\b|cesant[ií]as|prima\s+de\s+servicios|vacaciones|salarios?)/i;
    form.addEventListener('submit', function (e) {
        var descEl = form.querySelector('[name="description"]');
        var text = descEl ? String(descEl.value || '') : '';
        if (!payrollRe.test(text) || form.dataset.payrollWarned === '1') return;
        var ok = window.confirm(
            'Parece un pago de nómina, seguridad social o prestaciones.\n\n'
            + 'Esos costos ya se registran al liquidar la nómina. Si lo carga aquí como gasto, el costo queda DUPLICADO.\n\n'
            + 'Use Nómina → Pagos de nómina para registrar el pago.\n\n'
            + '¿Registrarlo de todos modos como gasto?'
        );
        if (!ok) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        form.dataset.payrollWarned = '1';
        setTimeout(function () { form.dataset.payrollWarned = ''; }, 3000);
    }, true);
})();
</script>
