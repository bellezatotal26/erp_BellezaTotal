<?php
$layout = 'tenant';
$title = 'Gastos - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gastos';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$expenses = $expenses ?? [];
$suppliers = $suppliers ?? [];
$categories = $categories ?? [];
$monthTotal = $monthTotal ?? 0;
$monthFinancial = $monthFinancial ?? 0;
$monthOperational = $monthOperational ?? 0;
$kindFilter = $kindFilter ?? '';
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$pagination = $pagination ?? null;
$paymentMethods = $paymentMethods ?? \SoftNova\Services\PaymentMethodCatalog::all();

function fmtG(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}

$financialCats = array_filter($categories, static fn($c) => ($c['kind'] ?? '') === 'financial');
$operationalCats = array_filter($categories, static fn($c) => ($c['kind'] ?? '') === 'operational');
?>
<?php echo flashMessage(); ?>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Gastos mes</h4><div class="stat-value" style="color:#DC2626;"><?php echo fmtG((float)$monthTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Financieros (mes)</h4><div class="stat-value" style="color:#B45309;"><?php echo fmtG((float)$monthFinancial, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Operativos (mes)</h4><div class="stat-value"><?php echo fmtG((float)$monthOperational, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Registros</h4><div class="stat-value"><?php echo (int)($pagination['total'] ?? count($expenses)); ?></div></div>
</div>

<div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <button onclick="document.getElementById('expenseModal').style.display='flex'" class="btn btn-primary neumorphic-btn" title="Nuevo gasto">Nuevo Gasto</button>
    <a href="<?php echo $viewInstance->route('app/gastos'); ?>?action=export" class="btn btn-secondary" title="Exportar CSV">Exportar CSV</a>
    <form method="GET" action="<?php echo $viewInstance->route('app/gastos'); ?>" style="display:flex;gap:8px;margin:0;align-items:center;">
        <select name="kind" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">Todos los tipos</option>
            <option value="financial" <?php echo $kindFilter === 'financial' ? 'selected' : ''; ?>>Financieros</option>
            <option value="operational" <?php echo $kindFilter === 'operational' ? 'selected' : ''; ?>>Operativos</option>
        </select>
    </form>
</div>

<p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
    <strong>Financieros:</strong> comisiones banco, datáfono, ventas, 4x1000.
    <strong>Operativos:</strong> arriendo, servicios, gasolina, etc. Cada categoría lleva su cuenta contable.
</p>

<div class="card neumorphic">
    <div class="card-header"><h3>Listado de Gastos</h3></div>
    <div class="card-body">
        <?php if (empty($expenses)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay gastos registrados</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr><th>Fecha</th><th>Descripcion</th><th>Categoria</th><th>Proveedor</th><th>Monto</th><th>Metodo</th><th>Soporte</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($expenses as $exp): ?>
                    <?php
                    $catLabel = $exp['category_label'] ?? ($exp['category'] ?? '');
                    $kindBadge = ($exp['category_kind'] ?? '') === 'financial' ? 'badge-warning' : 'badge-secondary';
                    $kindText = ($exp['category_kind'] ?? '') === 'financial' ? 'Financiero' : (($exp['category_kind'] ?? '') === 'operational' ? 'Operativo' : '');
                    $methodLabel = \SoftNova\Services\PaymentMethodCatalog::label((string)($exp['payment_method'] ?? 'cash'));
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($exp['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars($exp['description']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($catLabel); ?>
                            <?php if ($kindText): ?><br><span class="badge <?php echo $kindBadge; ?>" style="font-size:10px;"><?php echo $kindText; ?></span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($exp['supplier_name'] ?? '-'); ?></td>
                        <td style="font-weight:600;color:#DC2626;"><?php echo fmtG((float)$exp['amount'], $currency); ?></td>
                        <td><?php echo htmlspecialchars($methodLabel); ?></td>
                        <td>
                            <?php if (!empty($exp['receipt_path'])): ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo $viewInstance->route('app/gastos'); ?>?action=receipt&id=<?php echo (int)$exp['id']; ?>" target="_blank" title="Ver foto/PDF">Ver</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <form method="POST" action="<?php echo $viewInstance->route('app/gastos'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$exp['id']; ?>">
                                <button type="submit" onclick="return confirm('Eliminar gasto?')" class="btn btn-sm btn-danger" title="Eliminar">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $paginationBaseUrl = $viewInstance->route('app/gastos');
            $paginationQuery = array_filter(['kind' => $kindFilter !== '' ? $kindFilter : null]);
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>

<div id="expenseModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:600px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Nuevo Gasto</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('expenseModal').style.display='none'" title="Cerrar">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/gastos'); ?>?action=create" data-ajax="true" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Descripcion *</label>
                    <input type="text" name="description" class="form-control" required placeholder="Ej. Comisión datáfono marzo">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Monto *</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Categoria *</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Seleccionar…</option>
                            <optgroup label="Gastos financieros">
                                <?php foreach ($financialCats as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Gastos operativos">
                                <?php foreach ($operationalCats as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ($c['code'] ?? '') === 'general' ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Medio de pago</label>
                        <select name="payment_method" class="form-control">
                            <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash'); ?>
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
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="affect_cash" value="1" checked>
                        Ya pagado (si es efectivo, también registra egreso en caja)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('expenseModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>
