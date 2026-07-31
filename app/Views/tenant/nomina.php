<?php
$layout = 'tenant';
$title = 'Nómina - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Nómina';
$tab = $tab ?? 'employees';
$employees = $employees ?? [];
$runs = $runs ?? [];
$params = $params ?? [];
$detail = $detail ?? null;
$q = $q ?? '';
$statusFilter = $statusFilter ?? '';
$activeCount = $activeCount ?? 0;
$monthPayroll = $monthPayroll ?? 0;
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$base = $viewInstance->route('app/nomina');
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'nomina');
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'nomina');

function fmtN(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<?php echo flashMessage(); ?>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Empleados activos</h4><div class="stat-value"><?php echo (int)$activeCount; ?></div></div>
    <div class="stat-card neumorphic"><h4>Nómina neta del mes</h4><div class="stat-value" style="color:var(--color-primary);"><?php echo fmtN((float)$monthPayroll, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>SMMLV configurado</h4><div class="stat-value" style="font-size:18px;"><?php echo fmtN((float)($params['smmlv'] ?? 0), $currency); ?></div></div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
    <a class="btn <?php echo $tab === 'employees' ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $base; ?>?tab=employees">Empleados</a>
    <a class="btn <?php echo in_array($tab, ['runs', 'run_detail'], true) ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $base; ?>?tab=runs">Liquidaciones</a>
    <a class="btn <?php echo $tab === 'params' ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $base; ?>?tab=params">Parámetros</a>
</div>

<?php if ($tab === 'employees'): ?>
<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:end;">
    <form method="GET" action="<?php echo $base; ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="tab" value="employees">
        <div class="form-group" style="margin:0;"><label>Buscar</label><input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Nombre, cédula, cargo"></div>
        <div class="form-group" style="margin:0;"><label>Estado</label>
            <select class="form-control" name="status">
                <option value="">Todos</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Activos</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactivos</option>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="openEmployeeModal()">+ Empleado</button>
    <?php endif; ?>
</div>

<div class="card neumorphic">
    <div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Documento</th><th>Nombre</th><th>Cargo</th><th>Salario</th><th>Aux. transporte</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php if (!$employees): ?>
            <tr><td colspan="7" class="dashboard-empty">Registre empleados para generar liquidaciones.</td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $e): ?>
            <tr>
                <td><?php echo htmlspecialchars($e['document_type'] . ' ' . $e['document_number']); ?></td>
                <td><?php echo htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name'])); ?></td>
                <td><?php echo htmlspecialchars($e['position_title'] ?: '-'); ?></td>
                <td><?php echo fmtN((float)$e['salary'], $currency); ?></td>
                <td><?php echo !empty($e['has_transport_aid']) ? 'Sí' : 'No'; ?></td>
                <td><span class="badge <?php echo $e['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $e['status'] === 'active' ? 'Activo' : 'Inactivo'; ?></span></td>
                <td><?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-secondary" onclick='editEmployee(<?php echo json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>Editar</button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

<?php elseif ($tab === 'runs'): ?>
<div style="display:flex;gap:10px;margin-bottom:14px;">
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('runModal').style.display='flex'">Nueva liquidación</button>
    <?php endif; ?>
</div>
<div class="card neumorphic">
    <div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Nº</th><th>Periodo</th><th>Pago</th><th>Bruto</th><th>Deducciones</th><th>Neto</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php if (!$runs): ?>
            <tr><td colspan="8" class="dashboard-empty">Aún no hay liquidaciones.</td></tr>
        <?php endif; ?>
        <?php foreach ($runs as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['run_number']); ?></td>
                <td><?php echo htmlspecialchars($r['period_label']); ?></td>
                <td><?php echo date('d/m/Y', strtotime($r['pay_date'])); ?></td>
                <td><?php echo fmtN((float)$r['gross_total'], $currency); ?></td>
                <td><?php echo fmtN((float)$r['deductions_total'], $currency); ?></td>
                <td><strong><?php echo fmtN((float)$r['net_total'], $currency); ?></strong></td>
                <td><span class="badge"><?php echo htmlspecialchars($r['status']); ?></span></td>
                <td><a class="btn btn-sm btn-secondary" href="<?php echo $base; ?>?tab=runs&amp;action=run_detail&amp;id=<?php echo (int)$r['id']; ?>">Ver</a>
                    <a class="btn btn-sm btn-secondary" target="_blank" href="<?php echo $base; ?>?action=pdf&amp;id=<?php echo (int)$r['id']; ?>">PDF</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

<?php elseif ($tab === 'run_detail' && $detail): ?>
<?php $run = $detail['run']; $items = $detail['items']; ?>
<div style="margin-bottom:12px;"><a class="btn btn-secondary" href="<?php echo $base; ?>?tab=runs">← Volver</a></div>
<div class="card neumorphic" style="margin-bottom:16px;">
    <div class="card-header"><h3><?php echo htmlspecialchars($run['run_number']); ?> · <?php echo htmlspecialchars($run['period_label']); ?></h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;font-size:13px;">
            <div><span style="color:var(--color-text-secondary);">Estado</span><div><strong><?php echo htmlspecialchars($run['status']); ?></strong></div></div>
            <div><span style="color:var(--color-text-secondary);">Fecha pago</span><div><?php echo date('d/m/Y', strtotime($run['pay_date'])); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Bruto</span><div><?php echo fmtN((float)$run['gross_total'], $currency); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Deducciones</span><div><?php echo fmtN((float)$run['deductions_total'], $currency); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Neto</span><div><strong><?php echo fmtN((float)$run['net_total'], $currency); ?></strong></div></div>
            <div><span style="color:var(--color-text-secondary);">Aportes empleador</span><div><?php echo fmtN((float)$run['employer_total'], $currency); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Parafiscales</span><div><?php echo fmtN((float)($run['parafiscal_total'] ?? 0), $currency); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Primas</span><div><?php echo fmtN((float)($run['prima_total'] ?? 0), $currency); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Cesantías</span><div><?php echo fmtN((float)($run['cesantias_total'] ?? 0), $currency); ?></div></div>
            <div><span style="color:var(--color-text-secondary);">Incapacidades</span><div><?php echo fmtN((float)($run['incapacity_total'] ?? 0), $currency); ?></div></div>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-secondary" target="_blank" href="<?php echo $base; ?>?action=pdf&amp;id=<?php echo (int)$run['id']; ?>">PDF liquidación</a>
        <?php if (($run['status'] ?? '') === 'draft' && $canEdit): ?>
                <form method="POST" action="<?php echo $base; ?>?action=pay_run" data-ajax="true" style="display:inline;">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$run['id']; ?>">
                    <?php if (($run['payment_method'] ?? '') === 'cash'): ?>
                        <label style="margin-right:10px;"><input type="checkbox" name="affect_cash" value="1"> Afectar caja</label>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit" onclick="return confirm('¿Contabilizar asiento detallado (SS/parafiscales) y marcar como pagada?')">Contabilizar y pagar</button>
                </form>
                <form method="POST" action="<?php echo $base; ?>?action=cancel_run" data-ajax="true" style="display:inline;">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$run['id']; ?>">
                    <button class="btn btn-danger" type="submit" onclick="return confirm('¿Cancelar liquidación?')">Cancelar</button>
                </form>
        <?php endif; ?>
        </div>
    </div>
</div>
<div class="card neumorphic">
    <div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Empleado</th><th>Días</th><th>Inc.</th><th>Devengado</th><th>Prima</th><th>Cesantías</th><th>Salud</th><th>Pensión</th><th>Neto</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?php echo htmlspecialchars($it['employee_name']); ?></td>
                <td><?php echo (int)$it['days_worked']; ?></td>
                <td><?php echo (int)($it['incapacity_days'] ?? 0); ?></td>
                <td><?php echo fmtN((float)$it['salary_base'] + (float)($it['transport_aid'] ?? 0) + (float)($it['incapacity_pay'] ?? 0), $currency); ?></td>
                <td><?php echo fmtN((float)($it['prima'] ?? 0), $currency); ?></td>
                <td><?php echo fmtN((float)($it['cesantias'] ?? 0) + (float)($it['cesantias_interest'] ?? 0), $currency); ?></td>
                <td><?php echo fmtN((float)$it['health_employee'], $currency); ?></td>
                <td><?php echo fmtN((float)$it['pension_employee'], $currency); ?></td>
                <td><strong><?php echo fmtN((float)$it['net_pay'], $currency); ?></strong></td>
                <td style="white-space:nowrap;">
                    <a class="btn btn-sm btn-secondary" target="_blank" href="<?php echo $base; ?>?action=payslip&amp;run_id=<?php echo (int)$run['id']; ?>&amp;item_id=<?php echo (int)$it['id']; ?>">PDF</a>
                    <?php if (($run['status'] ?? '') === 'draft' && $canEdit): ?>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="editIncapacity(<?php echo (int)$it['id']; ?>,<?php echo (int)$run['id']; ?>,<?php echo (int)($it['incapacity_days'] ?? 0); ?>,'<?php echo htmlspecialchars($it['incapacity_type'] ?? 'enfermedad', ENT_QUOTES); ?>')">Inc.</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p style="font-size:12px;color:var(--color-text-secondary);margin-top:10px;">
        Al pagar se genera asiento: Débito nómina + aportes patronales · Crédito salud/pensión/ARL/caja/SENA/ICBF por pagar · Crédito banco/caja por el neto.
    </p>
    </div>
</div>

<?php elseif ($tab === 'params'): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Parámetros de liquidación (Colombia)</h3></div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--color-text-secondary);">Ajuste SMMLV, auxilio de transporte y % de aportes. La liquidación usa base 30 días.</p>
        <?php if ($canEdit): ?>
        <form method="POST" action="<?php echo $base; ?>?action=save_params" data-ajax="true" class="form-grid">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="form-group"><label>SMMLV</label><input class="form-control" type="number" step="0.01" name="smmlv" value="<?php echo htmlspecialchars((string)$params['smmlv']); ?>"></div>
            <div class="form-group"><label>Auxilio de transporte</label><input class="form-control" type="number" step="0.01" name="transport_aid" value="<?php echo htmlspecialchars((string)$params['transport_aid']); ?>"></div>
            <div class="form-group"><label>% Salud empleado</label><input class="form-control" type="number" step="0.01" name="health_employee_rate" value="<?php echo htmlspecialchars((string)$params['health_employee_rate']); ?>"></div>
            <div class="form-group"><label>% Pensión empleado</label><input class="form-control" type="number" step="0.01" name="pension_employee_rate" value="<?php echo htmlspecialchars((string)$params['pension_employee_rate']); ?>"></div>
            <div class="form-group"><label>% Salud empleador</label><input class="form-control" type="number" step="0.01" name="health_employer_rate" value="<?php echo htmlspecialchars((string)$params['health_employer_rate']); ?>"></div>
            <div class="form-group"><label>% Pensión empleador</label><input class="form-control" type="number" step="0.01" name="pension_employer_rate" value="<?php echo htmlspecialchars((string)$params['pension_employer_rate']); ?>"></div>
            <div class="form-group"><label>% ARL empleador</label><input class="form-control" type="number" step="0.001" name="arl_employer_rate" value="<?php echo htmlspecialchars((string)$params['arl_employer_rate']); ?>"></div>
            <div class="form-group"><label>% Caja compensación</label><input class="form-control" type="number" step="0.01" name="caja_employer_rate" value="<?php echo htmlspecialchars((string)$params['caja_employer_rate']); ?>"></div>
            <div class="form-group"><label>% SENA</label><input class="form-control" type="number" step="0.01" name="sena_employer_rate" value="<?php echo htmlspecialchars((string)$params['sena_employer_rate']); ?>"></div>
            <div class="form-group"><label>% ICBF</label><input class="form-control" type="number" step="0.01" name="icbf_employer_rate" value="<?php echo htmlspecialchars((string)$params['icbf_employer_rate']); ?>"></div>
            <div class="form-group"><label>% Incapacidad (días 3+)</label><input class="form-control" type="number" step="0.01" name="incapacity_rate" value="<?php echo htmlspecialchars((string)$params['incapacity_rate']); ?>"></div>
            <div class="form-group" style="grid-column:1/-1;"><button class="btn btn-primary" type="submit">Guardar parámetros</button></div>
        </form>
        <?php endif; ?>
        <p style="font-size:12px;margin-top:12px;color:var(--color-text-secondary);">
            Prima ≈ medio salario (semestre). Cesantías = provisión mensual salario/12 + interés. Incapacidad: días 1-2 al 100%, resto al %.
            Contabilidad: CxP salud (237005), pensión (238030), ARL, caja, SENA, ICBF.
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($canCreate || $canEdit): ?>
<div id="employeeModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:640px;">
        <div class="modal-header"><h3 id="employeeModalTitle">Empleado</h3><button type="button" class="modal-close" onclick="closeEmployeeModal()">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=save_employee" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="empId" value="0">
            <div class="modal-body"><div class="form-grid">
                <div class="form-group"><label>Tipo doc.</label><select class="form-control" name="document_type" id="empDocType"><option value="CC">CC</option><option value="CE">CE</option><option value="NIT">NIT</option><option value="PAS">PAS</option></select></div>
                <div class="form-group"><label>Número *</label><input class="form-control" name="document_number" id="empDoc" required></div>
                <div class="form-group"><label>Nombres *</label><input class="form-control" name="first_name" id="empFirst" required></div>
                <div class="form-group"><label>Apellidos</label><input class="form-control" name="last_name" id="empLast"></div>
                <div class="form-group"><label>Cargo</label><input class="form-control" name="position_title" id="empPos"></div>
                <div class="form-group"><label>Salario *</label><input class="form-control" type="number" step="0.01" min="0" name="salary" id="empSalary" required></div>
                <div class="form-group"><label>Fecha ingreso</label><input class="form-control" type="date" name="hire_date" id="empHire"></div>
                <div class="form-group"><label>Contrato</label><select class="form-control" name="contract_type" id="empContract"><option value="indefinido">Indefinido</option><option value="fijo">Término fijo</option><option value="obra">Obra/labor</option><option value="prestacion">Prestación de servicios</option></select></div>
                <div class="form-group"><label>Pago</label><select class="form-control" name="payment_method" id="empPay"><?php echo \SoftNova\Core\payment_method_options('transfer'); ?></select></div>
                <div class="form-group"><label>Cuenta bancaria</label><input class="form-control" name="bank_account" id="empBank"></div>
                <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" id="empEmail"></div>
                <div class="form-group"><label>Teléfono</label><input class="form-control" name="phone" id="empPhone"></div>
                <div class="form-group"><label>Estado</label><select class="form-control" name="status" id="empStatus"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></div>
                <div class="form-group"><label><input type="checkbox" name="has_transport_aid" id="empTransport" value="1" checked> Auxilio de transporte</label></div>
                <div class="form-group" style="grid-column:1/-1;"><label>Notas</label><textarea class="form-control" name="notes" id="empNotes" rows="2"></textarea></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeEmployeeModal()">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form>
    </div>
</div>

<div id="runModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header"><h3>Nueva liquidación</h3><button type="button" class="modal-close" onclick="document.getElementById('runModal').style.display='none'">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=create_run" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body"><div class="form-grid">
                <div class="form-group"><label>Año</label><input class="form-control" type="number" name="period_year" value="<?php echo date('Y'); ?>" required></div>
                <div class="form-group"><label>Mes</label><select class="form-control" name="period_month"><?php for ($i=1;$i<=12;$i++): ?><option value="<?php echo $i; ?>" <?php echo $i === (int)date('n') ? 'selected' : ''; ?>><?php echo $i; ?></option><?php endfor; ?></select></div>
                <div class="form-group"><label>Fecha de pago</label><input class="form-control" type="date" name="pay_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="form-group"><label>Días trabajados</label><input class="form-control" type="number" min="1" max="31" name="days_worked" value="30"></div>
                <div class="form-group" style="grid-column:1/-1;"><label>Medio de pago</label><select class="form-control" name="payment_method"><?php echo \SoftNova\Core\payment_method_options('transfer'); ?></select></div>
                <div class="form-group"><label><input type="checkbox" name="include_prima" value="1" <?php echo in_array((int)date('n'), [6, 12], true) ? 'checked' : ''; ?>> Incluir prima de servicios</label></div>
                <div class="form-group"><label><input type="checkbox" name="include_cesantias" value="1"> Incluir cesantías (provisión mes)</label></div>
                <div class="form-group" style="grid-column:1/-1;"><label><input type="checkbox" name="include_parafiscales" value="1" checked> Parafiscales (caja, SENA, ICBF) + ARL</label></div>
                <div class="form-group" style="grid-column:1/-1;"><label>Notas</label><input class="form-control" name="notes"></div>
                <p style="grid-column:1/-1;font-size:12px;color:var(--color-text-secondary);margin:0;">La incapacidad se puede ajustar después en el detalle de cada empleado.</p>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('runModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-primary">Calcular</button></div>
        </form>
    </div>
</div>
<script>
function openEmployeeModal() {
    document.getElementById('employeeModalTitle').textContent = 'Nuevo empleado';
    document.getElementById('empId').value = '0';
    ['empDoc','empFirst','empLast','empPos','empSalary','empHire','empBank','empEmail','empPhone','empNotes'].forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('empTransport').checked = true;
    document.getElementById('empStatus').value = 'active';
    document.getElementById('employeeModal').style.display = 'flex';
}
function editEmployee(e) {
    document.getElementById('employeeModalTitle').textContent = 'Editar empleado';
    document.getElementById('empId').value = e.id || 0;
    document.getElementById('empDocType').value = e.document_type || 'CC';
    document.getElementById('empDoc').value = e.document_number || '';
    document.getElementById('empFirst').value = e.first_name || '';
    document.getElementById('empLast').value = e.last_name || '';
    document.getElementById('empPos').value = e.position_title || '';
    document.getElementById('empSalary').value = e.salary || 0;
    document.getElementById('empHire').value = e.hire_date || '';
    document.getElementById('empContract').value = e.contract_type || 'indefinido';
    document.getElementById('empPay').value = e.payment_method || 'transfer';
    document.getElementById('empBank').value = e.bank_account || '';
    document.getElementById('empEmail').value = e.email || '';
    document.getElementById('empPhone').value = e.phone || '';
    document.getElementById('empStatus').value = e.status || 'active';
    document.getElementById('empTransport').checked = String(e.has_transport_aid) === '1';
    document.getElementById('empNotes').value = e.notes || '';
    document.getElementById('employeeModal').style.display = 'flex';
}
function closeEmployeeModal(){ document.getElementById('employeeModal').style.display='none'; }
function editIncapacity(itemId, runId, days, type) {
    document.getElementById('incItemId').value = itemId;
    document.getElementById('incRunId').value = runId;
    document.getElementById('incDays').value = days || 0;
    document.getElementById('incType').value = type || 'enfermedad';
    document.getElementById('incapacityModal').style.display = 'flex';
}
</script>
<div id="incapacityModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header"><h3>Incapacidad</h3><button type="button" class="modal-close" onclick="document.getElementById('incapacityModal').style.display='none'">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=save_incapacity" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="item_id" id="incItemId">
            <input type="hidden" name="run_id" id="incRunId">
            <div class="modal-body">
                <div class="form-group"><label>Días de incapacidad</label><input class="form-control" type="number" min="0" max="31" name="incapacity_days" id="incDays"></div>
                <div class="form-group"><label>Tipo</label>
                    <select class="form-control" name="incapacity_type" id="incType">
                        <option value="enfermedad">Enfermedad general</option>
                        <option value="laboral">Accidente / enfermedad laboral</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('incapacityModal').style.display='none'">Cerrar</button>
                <button type="submit" class="btn btn-primary">Recalcular</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
