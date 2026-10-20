<?php
$layout = 'tenant';
$title = 'Nómina - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Nómina';
$tab = $tab ?? 'employees';
$employees = $employees ?? [];
$runs = $runs ?? [];
$params = $params ?? [];
$schedules = $schedules ?? [];
$detail = $detail ?? null;
$q = $q ?? '';
$statusFilter = $statusFilter ?? '';
$scheduleFilter = $scheduleFilter ?? null;
$runFilters = $runFilters ?? ['year' => 0, 'status' => ''];
$runYears = $runYears ?? [];
$activeCount = $activeCount ?? 0;
$monthPayroll = $monthPayroll ?? 0;
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$base = $viewInstance->route('app/nomina');
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'nomina');
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'nomina');
$canDelete = \SoftNova\Core\TenantMiddleware::canDo('delete', 'nomina');
$weekdays = \SoftNova\Services\PayrollService::WEEKDAYS;
$weekShort = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

if (!function_exists('fmtN')) {
    function fmtN(float $a, array $c): string
    {
        return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
    }
}
if (!function_exists('nmTip')) {
    function nmTip(string $text): string
    {
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return '<span class="nm-tip" tabindex="0" role="note" aria-label="' . $t . '" data-tip="' . $t . '">i</span>';
    }
}
if (!function_exists('nmHours')) {
    function nmHours(float $h): string
    {
        return rtrim(rtrim(number_format($h, 2, ',', '.'), '0'), ',') . ' h';
    }
}
$scheduleChip = static function (?array $s): string {
    if (!$s) {
        return '<span class="nm-chip nm-chip-empty">Sin horario</span>';
    }
    return '<span class="nm-chip" style="--sc:' . htmlspecialchars($s['color']) . '"><span class="nm-dot"></span>'
        . htmlspecialchars($s['name']) . '</span>';
};
$hasNightSchedules = (bool)array_filter($schedules, static fn ($s) => (float)$s['metrics']['weekly_night_hours'] > 0);
$hasOvertimeSchedules = (bool)array_filter($schedules, static fn ($s) => (float)($s['metrics']['weekly_extra_hours'] ?? 0) > 0);
?>
<?php echo flashMessage(); ?>

<style>
.nm-tabs{display:flex;gap:4px;flex-wrap:wrap;padding:4px;margin-bottom:18px;border-radius:12px;background:var(--color-secondary);border:1px solid var(--color-border);width:fit-content;max-width:100%}
.nm-tabs a{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:9px;font-size:14px;font-weight:600;color:var(--color-text-secondary);text-decoration:none;transition:background .15s,color .15s}
.nm-tabs a:hover{color:var(--color-text-primary)}
.nm-tabs a.active{background:var(--color-white);color:var(--color-accent-text, var(--color-primary));box-shadow:0 1px 4px rgba(0,0,0,.08)}
.nm-tabs .nm-count{font-size:11px;padding:1px 7px;border-radius:999px;background:var(--color-border);color:var(--color-text-secondary)}
.nm-tabs a.active .nm-count{background:var(--color-primary);color:#fff}
.nm-tip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-left:6px;border-radius:50%;background:var(--color-border);color:var(--color-text-secondary);font:italic 700 11px/1 Georgia,serif;cursor:help;vertical-align:middle;flex-shrink:0;user-select:none}
.nm-tip:hover,.nm-tip:focus{background:var(--color-primary);color:#fff;outline:none}
.nm-tip-pop{position:fixed;z-index:20000;max-width:300px;padding:9px 12px;border-radius:8px;background:#1f2937;color:#f9fafb;font-size:12px;line-height:1.45;box-shadow:0 8px 24px rgba(0,0,0,.25);pointer-events:none;display:none}
.nm-section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.nm-section-head h3{margin:0 0 4px;font-size:17px}
.nm-section-head p{margin:0;font-size:13px;color:var(--color-text-secondary)}
.nm-param-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px}
.nm-group{background:var(--color-white);border:1px solid var(--color-border);border-top:3px solid var(--acc,#0D7C4A);border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:12px}
.nm-group.wide{grid-column:1/-1}
.nm-group-head h4{margin:0;font-size:15px;display:flex;align-items:center;gap:8px}
.nm-group-head h4 .nm-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:var(--acc);color:#fff;font-size:12px}
.nm-group-head p{margin:4px 0 0;font-size:12px;color:var(--color-text-secondary)}
.nm-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.nm-field label{display:flex;align-items:center;font-size:12px;font-weight:600;margin-bottom:5px;color:var(--color-text-primary)}
.nm-input{position:relative;display:flex;align-items:center}
.nm-input .form-control{width:100%}
.nm-input .nm-affix{position:absolute;font-size:12px;font-weight:600;color:var(--color-text-secondary);pointer-events:none}
.nm-input.suffix .nm-affix{right:10px}.nm-input.suffix .form-control{padding-right:30px}
.nm-input.prefix .nm-affix{left:10px}.nm-input.prefix .form-control{padding-left:24px}
.nm-commission-table td{vertical-align:middle}.nm-commission-table .nm-input{max-width:240px}
.nm-input .form-control.nm-changed{border-color:var(--color-warning);box-shadow:0 0 0 3px rgba(245,158,11,.18)}
.nm-preview{display:flex;flex-wrap:wrap;gap:6px;padding-top:10px;border-top:1px dashed var(--color-border);font-size:12px;color:var(--color-text-secondary)}
.nm-preview b{color:var(--color-text-primary)}
.nm-pill{padding:3px 9px;border-radius:999px;background:var(--color-secondary);border:1px solid var(--color-border)}
.nm-savebar{position:sticky;bottom:0;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px;padding:12px 16px;border-radius:12px;background:var(--color-white);border:1px solid var(--color-border);box-shadow:0 -4px 16px rgba(0,0,0,.06)}
.nm-savebar .nm-note{font-size:12px;color:var(--color-text-secondary)}
.nm-footnote{font-size:12px;color:var(--color-text-secondary);margin-top:12px;line-height:1.5}
.nm-sched-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:18px}
.nm-sched-card{background:var(--color-white);border:1px solid var(--color-border);border-left:4px solid var(--sc);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:10px}
.nm-sched-top{display:flex;align-items:center;gap:8px}
.nm-sched-top strong{flex:1;font-size:15px}
.nm-badge{font-size:12px;font-weight:600;padding:3px 9px;border-radius:999px;background:var(--color-secondary);border:1px solid var(--color-border)}
.nm-badge.warn{background:rgba(245,158,11,.12);border-color:var(--color-warning);color:var(--dmc-B45309, #b45309)}
.nm-week{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.nm-day{text-align:center;border-radius:8px;padding:6px 2px;font-size:10px;line-height:1.3;background:var(--color-secondary);color:var(--color-text-secondary);border:1px solid var(--color-border)}
.nm-day b{display:block;font-size:12px;margin-bottom:2px}
.nm-day.on{background:color-mix(in srgb,var(--sc) 14%,transparent);border-color:color-mix(in srgb,var(--sc) 45%,transparent);color:var(--color-text-primary)}
.nm-sched-meta{display:flex;flex-wrap:wrap;gap:6px;font-size:12px;color:var(--color-text-secondary)}
.nm-warn{margin:0;padding:8px 10px 8px 26px;border-radius:8px;background:rgba(245,158,11,.1);color:var(--dmc-B45309, #b45309);font-size:12px;line-height:1.5}
.nm-actions{display:flex;gap:6px;justify-content:flex-end}
.nm-empty{padding:28px;text-align:center;border:2px dashed var(--color-border);border-radius:12px;color:var(--color-text-secondary);font-size:14px}
.nm-chip{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:3px 10px;border-radius:999px;background:color-mix(in srgb,var(--sc,#64748B) 12%,transparent);border:1px solid color-mix(in srgb,var(--sc,#64748B) 40%,transparent);white-space:nowrap}
.nm-chip-empty{--sc:#94A3B8;color:var(--color-text-secondary)}
.nm-dot{width:8px;height:8px;border-radius:50%;background:var(--sc)}
.nm-assign-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.nm-assign-bar .form-group{margin:0}
.nm-week-editor{width:100%;border-collapse:collapse}
.nm-week-editor th{font-size:11px;text-transform:uppercase;text-align:left;padding:6px}
.nm-week-editor td{padding:4px 6px;border-top:1px solid var(--color-border)}
.nm-week-editor tr.off td:not(:first-child){opacity:.4}
.nm-week-editor input[type=time]{min-width:100px}
.nm-week-editor input[type=number]{width:80px}
.nm-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:12px}
.nm-summary div{padding:8px 10px;border-radius:8px;background:var(--color-secondary);border:1px solid var(--color-border);font-size:12px;color:var(--color-text-secondary)}
.nm-summary b{display:block;font-size:16px;color:var(--color-text-primary)}
.nm-templates{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.nm-modal-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.nm-modal-grid .full{grid-column:1/-1}
.nm-check{display:flex;align-items:center;gap:8px;font-size:13px}
.nm-void-form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}
.nm-void-form .form-control{width:auto;min-width:150px;max-width:260px}
</style>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Empleados activos</h4><div class="stat-value"><?php echo (int)$activeCount; ?></div></div>
    <div class="stat-card neumorphic"><h4>Nómina neta del mes</h4><div class="stat-value" style="color:var(--color-accent-text, var(--color-primary));"><?php echo fmtN((float)$monthPayroll, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>SMMLV configurado</h4><div class="stat-value" style="font-size:18px;"><?php echo fmtN((float)($params['smmlv'] ?? 0), $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Horarios</h4><div class="stat-value" style="font-size:18px;"><?php echo count($schedules); ?></div></div>
</div>

<nav class="nm-tabs">
    <a class="<?php echo $tab === 'employees' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=employees">Empleados</a>
    <a class="<?php echo $tab === 'schedules' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=schedules">Horarios <span class="nm-count"><?php echo count($schedules); ?></span></a>
    <a class="<?php echo in_array($tab, ['runs', 'run_detail'], true) ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=runs">Liquidaciones</a>
    <a class="<?php echo $tab === 'payments' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=payments">Pagos de nómina</a>
    <a class="<?php echo $tab === 'params' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=params">Parámetros</a>
</nav>

<?php if ($tab === 'employees'): ?>
<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:end;justify-content:space-between;">
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
        <div class="form-group" style="margin:0;"><label>Horario</label>
            <select class="form-control" name="schedule">
                <option value="">Todos</option>
                <option value="0" <?php echo $scheduleFilter === 0 ? 'selected' : ''; ?>>Sin horario</option>
                <?php foreach ($schedules as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo $scheduleFilter === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Filtrar</button>
        <?php if ($q !== '' || $statusFilter !== '' || $scheduleFilter !== null): ?>
            <a class="btn btn-secondary" href="<?php echo $base; ?>?tab=employees">Limpiar</a>
        <?php endif; ?>
    </form>
    <?php
    $empExportQs = http_build_query(array_filter(['q' => $q, 'status' => $statusFilter, 'schedule' => $scheduleFilter], static fn ($v) => $v !== '' && $v !== null));
    $empExportQs = $empExportQs !== '' ? '&' . $empExportQs : '';
    ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary" data-export-download href="<?php echo $base; ?>?action=export_employees&amp;format=csv<?php echo htmlspecialchars($empExportQs); ?>" title="Descargar CSV con los filtros aplicados">CSV</a>
        <a class="btn btn-secondary" data-export-download href="<?php echo $base; ?>?action=export_employees&amp;format=pdf<?php echo htmlspecialchars($empExportQs); ?>" title="Descargar PDF con los filtros aplicados">PDF</a>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary" onclick="openEmployeeModal()">+ Empleado</button>
        <?php endif; ?>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Documento</th><th>Nombre</th><th>Cargo</th><th>Salario</th><th>Aux. transporte</th><th>Horario</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php if (!$employees): ?>
            <tr><td colspan="8" class="dashboard-empty">Registre empleados para generar liquidaciones.</td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $e): ?>
            <tr>
                <td><?php echo htmlspecialchars($e['document_type'] . ' ' . $e['document_number']); ?></td>
                <td><?php echo htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name'])); ?></td>
                <td><?php echo htmlspecialchars($e['position_title'] ?: '-'); ?></td>
                <td><?php echo fmtN((float)$e['salary'], $currency); ?></td>
                <td><?php echo !empty($e['has_transport_aid']) ? 'Sí' : 'No'; ?></td>
                <td><?php echo $scheduleChip($schedules[(int)($e['schedule_id'] ?? 0)] ?? null); ?></td>
                <td><span class="badge <?php echo $e['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $e['status'] === 'active' ? 'Activo' : 'Inactivo'; ?></span></td>
                <td><?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-secondary" onclick='editEmployee(<?php echo json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>)'>Editar</button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

<?php elseif ($tab === 'schedules'): ?>
<div class="nm-section-head">
    <div>
        <h3>Horarios de trabajo</h3>
        <p>Defina turnos semanales y aplíquelos a los empleados. Se calculan horas semanales, nocturnas (desde las <?php echo (int)($params['night_start_hour'] ?? 19); ?>:00) y dominicales.</p>
    </div>
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="openScheduleModal()">+ Nuevo horario</button>
    <?php endif; ?>
</div>

<?php if (!$schedules): ?>
    <div class="nm-empty" style="margin-bottom:18px;">
        Aún no hay horarios. Cree uno (p. ej. <strong>Lunes a sábado 9:00–17:00</strong>) y luego aplíquelo a sus empleados.
    </div>
<?php else: ?>
<div class="nm-sched-grid">
    <?php foreach ($schedules as $s): ?>
        <?php $m = $s['metrics']; $over = (float)$m['weekly_hours'] > (float)($params['max_weekly_hours'] ?? 42) + 0.001; ?>
        <div class="nm-sched-card" style="--sc:<?php echo htmlspecialchars($s['color']); ?>">
            <div class="nm-sched-top">
                <span class="nm-dot"></span>
                <strong><?php echo htmlspecialchars($s['name']); ?></strong>
                <span class="nm-badge <?php echo $over ? 'warn' : ''; ?>"><?php echo nmHours((float)$m['weekly_hours']); ?>/sem</span>
            </div>
            <div class="nm-week">
                <?php foreach ($weekShort as $n => $abbr): ?>
                    <?php $d = $s['days'][$n]; ?>
                    <div class="nm-day <?php echo $d['on'] ? 'on' : ''; ?>" title="<?php echo htmlspecialchars($weekdays[$n]); ?>">
                        <b><?php echo $abbr; ?></b>
                        <?php if ($d['on']): ?><?php echo htmlspecialchars($d['start']); ?><br><?php echo htmlspecialchars($d['end']); ?><?php else: ?>Libre<?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="nm-sched-meta">
                <span class="nm-pill"><?php echo (int)$s['employee_count']; ?> empleado(s)</span>
                <?php if ((float)$m['weekly_night_hours'] > 0): ?><span class="nm-pill">Nocturnas <?php echo nmHours((float)$m['weekly_night_hours']); ?>/sem</span><?php endif; ?>
                <?php if ((float)$m['weekly_sunday_hours'] > 0): ?><span class="nm-pill">Domingo <?php echo nmHours((float)$m['weekly_sunday_hours']); ?></span><?php endif; ?>
                <?php if ((float)($m['weekly_extra_hours'] ?? 0) > 0): ?><span class="nm-pill" style="color:var(--dmc-B45309, #B45309);">HE <?php echo nmHours((float)$m['weekly_extra_hours']); ?>/sem</span><?php endif; ?>
                <span class="nm-pill">≈ <?php echo nmHours((float)$m['monthly_hours']); ?>/mes</span>
            </div>
            <?php if ($m['warnings']): ?>
                <ul class="nm-warn"><?php foreach ($m['warnings'] as $w): ?><li><?php echo htmlspecialchars($w); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (!empty($s['notes'])): ?><div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars($s['notes']); ?></div><?php endif; ?>
            <div class="nm-actions">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="openScheduleModal(<?php echo (int)$s['id']; ?>)">Editar</button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="POST" action="<?php echo $base; ?>?action=delete_schedule" data-ajax="true" style="display:inline;">
                        <?php echo \SoftNova\Core\csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar el horario «<?php echo htmlspecialchars(addslashes($s['name']), ENT_QUOTES); ?>»? Los empleados asignados quedarán sin horario.')">Eliminar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header"><h3>Aplicar horario a empleados <?php echo nmTip('Marque los empleados, elija el horario y pulse Aplicar. El horario sirve para alertas de jornada y para precargar el recargo nocturno (RNO) y las horas extra (exceso sobre la jornada diaria) al crear la liquidación.'); ?></h3></div>
    <div class="card-body">
        <?php if (!$employees): ?>
            <div class="nm-empty">No hay empleados activos.</div>
        <?php else: ?>
        <form method="POST" action="<?php echo $base; ?>?action=assign_schedule" data-ajax="true" id="assignForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="nm-assign-bar">
                <div class="form-group"><label>Horario a aplicar</label>
                    <select class="form-control" name="schedule_id" required <?php echo $canEdit ? '' : 'disabled'; ?>>
                        <?php foreach ($schedules as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo nmHours((float)$s['metrics']['weekly_hours']); ?>/sem)</option>
                        <?php endforeach; ?>
                        <option value="0">— Quitar horario —</option>
                    </select>
                </div>
                <div class="form-group"><label>Buscar</label><input class="form-control" type="search" id="assignSearch" placeholder="Nombre o cargo" autocomplete="off"></div>
                <?php if ($canEdit): ?>
                    <button type="submit" class="btn btn-primary" id="assignBtn" disabled>Aplicar a seleccionados (<span id="assignCount">0</span>)</button>
                <?php endif; ?>
            </div>
            <div class="table-container"><table>
                <thead><tr>
                    <th style="width:36px;"><input type="checkbox" id="assignAll" aria-label="Seleccionar todos"></th>
                    <th>Empleado</th><th>Cargo</th><th>Horario actual</th>
                    <th>H/semana</th>
                    <th>RNO estimado/mes <?php echo nmTip('Horas de la jornada ordinaria que caen en horario nocturno, estimadas para 30 días. Se pueden precargar al crear la liquidación.'); ?></th>
                    <th>HE estimadas/mes <?php echo nmTip('Horas que superan la jornada máxima diaria de Parámetros, estimadas para 30 días. Se pueden precargar al crear la liquidación.'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($employees as $e): ?>
                    <?php $s = $schedules[(int)($e['schedule_id'] ?? 0)] ?? null; $name = trim($e['first_name'] . ' ' . $e['last_name']); ?>
                    <tr data-search="<?php echo htmlspecialchars(mb_strtolower($name . ' ' . ($e['position_title'] ?? ''))); ?>">
                        <td><input type="checkbox" name="employee_ids[]" value="<?php echo (int)$e['id']; ?>" class="assign-cb"></td>
                        <td><?php echo htmlspecialchars($name); ?></td>
                        <td><?php echo htmlspecialchars($e['position_title'] ?: '-'); ?></td>
                        <td><?php echo $scheduleChip($s); ?></td>
                        <td><?php echo $s ? nmHours((float)$s['metrics']['weekly_hours']) : '—'; ?></td>
                        <td><?php echo $s && (float)$s['metrics']['weekly_night_hours'] > 0 ? nmHours(round((float)$s['metrics']['weekly_night_hours'] * 30 / 7 * 4) / 4) : '—'; ?></td>
                        <td><?php echo $s && (float)($s['metrics']['weekly_extra_hours'] ?? 0) > 0 ? nmHours(round((float)$s['metrics']['weekly_extra_hours'] * 30 / 7 * 4) / 4) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'runs'): ?>
<?php
$runStatus = ['draft' => ['Borrador', 'badge-warning'], 'paid' => ['Pagada', 'badge-success'], 'posted' => ['Contabilizada', 'badge-success'], 'cancelled' => ['Cancelada', '']];
$runFiltered = $runFilters['year'] > 0 || $runFilters['status'] !== '';
$runExportQs = http_build_query(array_filter(['year' => $runFilters['year'] ?: '', 'run_status' => $runFilters['status']], static fn ($v) => $v !== ''));
$runExportQs = $runExportQs !== '' ? '&' . $runExportQs : '';
?>
<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:end;justify-content:space-between;">
    <form method="GET" action="<?php echo $base; ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="tab" value="runs">
        <div class="form-group" style="margin:0;"><label>Año</label>
            <select class="form-control" name="year">
                <option value="">Todos</option>
                <?php foreach ($runYears as $yr): ?>
                    <option value="<?php echo (int)$yr; ?>" <?php echo (int)$runFilters['year'] === (int)$yr ? 'selected' : ''; ?>><?php echo (int)$yr; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;"><label>Estado</label>
            <select class="form-control" name="run_status">
                <option value="">Todos</option>
                <?php foreach ($runStatus as $stKey => [$stName]): ?>
                    <option value="<?php echo $stKey; ?>" <?php echo $runFilters['status'] === $stKey ? 'selected' : ''; ?>><?php echo $stName; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Filtrar</button>
        <?php if ($runFiltered): ?>
            <a class="btn btn-secondary" href="<?php echo $base; ?>?tab=runs">Limpiar</a>
        <?php endif; ?>
    </form>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary" data-export-download href="<?php echo $base; ?>?action=export_runs&amp;format=csv<?php echo htmlspecialchars($runExportQs); ?>" title="Descargar CSV con los filtros aplicados (todas las columnas de totales)">CSV</a>
        <a class="btn btn-secondary" data-export-download href="<?php echo $base; ?>?action=export_runs&amp;format=pdf<?php echo htmlspecialchars($runExportQs); ?>" title="Descargar PDF con los filtros aplicados">PDF</a>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('runModal').style.display='flex'">Nueva liquidación</button>
        <?php endif; ?>
    </div>
</div>
<div class="card neumorphic">
    <div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Nº</th><th>Periodo</th><th>Pago</th><th>Empleados</th><th>Bruto</th><th>Deducciones</th><th>Neto</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php if (!$runs): ?>
            <tr><td colspan="9" class="dashboard-empty"><?php echo $runFiltered ? 'No hay liquidaciones con esos filtros.' : 'Aún no hay liquidaciones.'; ?></td></tr>
        <?php endif; ?>
        <?php foreach ($runs as $r): ?>
            <?php [$stLabel, $stClass] = $runStatus[$r['status']] ?? [$r['status'], '']; ?>
            <tr>
                <td><?php echo htmlspecialchars($r['run_number']); ?></td>
                <td><?php echo htmlspecialchars($r['period_label']); ?></td>
                <td><?php echo date('d/m/Y', strtotime($r['pay_date'])); ?></td>
                <td><?php echo (int)($r['employee_count'] ?? 0); ?></td>
                <td><?php echo fmtN((float)$r['gross_total'], $currency); ?></td>
                <td><?php echo fmtN((float)$r['deductions_total'], $currency); ?></td>
                <td><strong><?php echo fmtN((float)$r['net_total'], $currency); ?></strong></td>
                <td><span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($stLabel); ?></span></td>
                <td><a class="btn btn-sm btn-secondary" href="<?php echo $base; ?>?tab=runs&amp;action=run_detail&amp;id=<?php echo (int)$r['id']; ?>">Ver</a>
                    <a class="btn btn-sm btn-secondary" target="_blank" href="<?php echo $base; ?>?action=pdf&amp;id=<?php echo (int)$r['id']; ?>">PDF</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

<?php elseif ($tab === 'run_detail' && $detail): ?>
<?php
$run = $detail['run'];
$items = $detail['items'];
$accountingEntry = $accountingEntry ?? null;
$paymentEntry = $paymentEntry ?? null;
$accrualDate = \SoftNova\Services\AccountingService::payrollAccrualDate($run);

$sumCols = ['salary_base', 'transport_aid', 'other_earnings', 'incapacity_pay', 'prima', 'commission_amount',
    'health_employee', 'pension_employee', 'other_deductions',
    'health_employer', 'pension_employer', 'arl_employer', 'caja_employer', 'sena_employer', 'icbf_employer',
    'cesantias', 'cesantias_interest', 'vacation_provision'];
$tot = array_fill_keys($sumCols, 0.0);
foreach ($items as $it) {
    foreach ($sumCols as $c) {
        $tot[$c] += (float)($it[$c] ?? 0);
    }
}
$quincenas = \SoftNova\Services\PayrollService::quincenasInPeriod((int)($run['days_worked'] ?? 30));
$runSections = [
    ['Devengado', 'Lo que gana el empleado en el periodo.', '#0D7C4A', [
        ['Salarios (días trabajados)', $tot['salary_base'], null],
        ['Auxilio de transporte', $tot['transport_aid'], 'Proporcional a los días trabajados; solo salarios hasta 2 SMMLV. No es base de aportes.'],
        ['Horas extras y recargos', $tot['other_earnings'], 'HED, HEN, RNO, dominicales, HEDD y HEND. Sí son base de aportes.'],
        ['Incapacidades', $tot['incapacity_pay'], 'Días 1 y 2 al 100%; desde el día 3 al % de Parámetros (laboral: 100%).'],
        ['Prima de servicios', $tot['prima'], 'Solo si la liquidación incluye prima (normalmente junio y diciembre).'],
        ['Comisiones fijas', $tot['commission_amount'], 'Pago no salarial: valor quincenal × ' . $quincenas . ' quincena(s) de este periodo. No genera aportes.'],
    ], (float)$run['gross_total'], 'Total devengado'],
    ['Deducciones al empleado', 'Se descuentan del devengado y se pagan a seguridad social.', '#F59E0B', [
        ['Salud (EPS)', $tot['health_employee'], null],
        ['Pensión', $tot['pension_employee'], null],
        ['Otras deducciones', $tot['other_deductions'], null],
    ], (float)$run['deductions_total'], 'Total deducciones'],
    ['Aportes de la empresa', 'Costo a cargo del empleador. No afecta el neto.', '#EF4444', [
        ['Salud empresa', $tot['health_employer'], null],
        ['Pensión empresa', $tot['pension_employer'], null],
        ['ARL', $tot['arl_employer'], null],
        ['Caja de compensación', $tot['caja_employer'], null],
        ['SENA', $tot['sena_employer'], null],
        ['ICBF', $tot['icbf_employer'], null],
    ], (float)$run['employer_total'] + (float)($run['parafiscal_total'] ?? 0), 'Total aportes'],
    ['Provisiones', 'Prestaciones que se reservan para pagar después. No afecta el neto.', '#0EA5E9', [
        ['Cesantías', $tot['cesantias'], 'Se consignan al fondo de cesantías (cuenta 261005).'],
        ['Intereses sobre cesantías', $tot['cesantias_interest'], 'Se pagan al empleado en enero.'],
        ['Vacaciones', $tot['vacation_provision'], 'Se pagan cuando el empleado disfruta sus vacaciones (cuenta 261015).'],
    ], (float)($run['cesantias_total'] ?? 0) + (float)($run['vacation_total'] ?? 0), 'Total provisiones'],
];
$aportesTotal = $runSections[2][4];
$provTotal = $runSections[3][4];
$companyCost = (float)$run['gross_total'] + $aportesTotal + $provTotal;
$costParts = [
    ['Neto a empleados', (float)$run['net_total'], '#0D7C4A'],
    ['Deducciones (seg. social)', (float)$run['deductions_total'], '#F59E0B'],
    ['Aportes empresa', $aportesTotal, '#EF4444'],
    ['Provisiones', $provTotal, '#0EA5E9'],
];
$runStatusInfo = [
    'draft' => ['Borrador', 'warn', 'Puede ajustar incapacidades y horas extras antes de contabilizar.'],
    'paid' => ['Pagada', 'ok', 'Contabilizada y marcada como pagada. El gasto quedó en Gastos por el costo total de la empresa y solo se corrige desde aquí con «Anular pago».'],
    'posted' => ['Contabilizada', 'ok', 'Asiento contable generado.'],
    'cancelled' => ['Cancelada', 'off', 'Esta liquidación no se contabiliza. Puede crear una nueva para el mismo periodo.'],
];
[$stName, $stTone, $stHint] = $runStatusInfo[$run['status'] ?? ''] ?? [(string)($run['status'] ?? ''), 'off', ''];
$monthNames = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$periodName = ($monthNames[(int)($run['period_month'] ?? 0)] ?? '') . ' ' . (int)($run['period_year'] ?? 0);
$includes = [
    ['Prima', !empty($run['include_prima'])],
    ['Cesantías', !empty($run['include_cesantias'])],
    ['Vacaciones', !empty($run['include_vacaciones'])],
    ['Parafiscales', !empty($run['include_parafiscales'])],
];
?>
<style>
.nm-run-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}
.nm-run-head h3{margin:0;font-size:19px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.nm-run-head small{font-size:13px;font-weight:500;color:var(--color-text-secondary)}
.nm-status{font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;border:1px solid}
.nm-status.warn{background:rgba(245,158,11,.12);border-color:#F59E0B;color:var(--dmc-B45309, #B45309)}
.nm-status.ok{background:rgba(13,124,74,.1);border-color:#0D7C4A;color:var(--dmc-0D7C4A, #0D7C4A)}
.nm-status.off{background:var(--color-secondary);border-color:var(--color-border);color:var(--color-text-secondary)}
.nm-run-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;font-size:12px;color:var(--color-text-secondary)}
.nm-inc{padding:3px 9px;border-radius:999px;font-size:12px;border:1px solid var(--color-border)}
.nm-inc.on{background:rgba(13,124,74,.08);border-color:rgba(13,124,74,.4);color:var(--dmc-0D7C4A, #0D7C4A)}
.nm-inc.no{color:var(--color-text-secondary);text-decoration:line-through;opacity:.7}
.nm-run-banner{margin-top:12px;padding:10px 14px;border-radius:10px;font-size:13px;background:var(--color-secondary);border:1px solid var(--color-border);color:var(--color-text-secondary)}
.nm-run-banner.warn{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.4);color:var(--dmc-92400E, #92400E)}
.nm-flow{display:grid;grid-template-columns:1fr auto 1fr auto 1.2fr;gap:10px;align-items:stretch;margin-top:16px}
.nm-flow .op{align-self:center;font-size:22px;font-weight:700;color:var(--color-text-secondary)}
.nm-kpi{padding:14px 16px;border-radius:12px;background:var(--color-white);border:1px solid var(--color-border);border-left:4px solid var(--k)}
.nm-kpi span{display:block;font-size:12px;color:var(--color-text-secondary)}
.nm-kpi b{display:block;font-size:20px;margin-top:4px;color:var(--color-text-primary)}
.nm-kpi.main{background:color-mix(in srgb,var(--k) 8%,var(--color-white))}
.nm-kpi.main b{font-size:24px;color:var(--k)}
.nm-seg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}
@media (min-width:1400px){.nm-seg-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media (max-width:700px){.nm-seg-grid{grid-template-columns:1fr}}
.nm-seg{border:1px solid var(--color-border);border-top:3px solid var(--k);border-radius:12px;padding:12px 14px;background:var(--color-white);display:flex;flex-direction:column}
.nm-seg h4{margin:0;font-size:14px}
.nm-seg p{margin:2px 0 10px;font-size:11px;color:var(--color-text-secondary)}
.nm-seg-row{display:grid;grid-template-columns:1fr auto;gap:2px 10px;padding:5px 0;font-size:13px;border-bottom:1px dashed var(--color-border)}
.nm-seg-row.zero{color:var(--color-text-secondary);opacity:.6}
.nm-seg-row .amt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.nm-seg-row .bar{grid-column:1/-1;height:4px;border-radius:4px;background:var(--color-secondary);overflow:hidden}
.nm-seg-row .bar i{display:block;height:100%;background:var(--k)}
.nm-seg-total{display:flex;justify-content:space-between;margin-top:auto;padding-top:9px;font-weight:700;font-size:14px}
.nm-seg-total b{color:var(--k)}
.nm-cost{margin-top:16px;padding:14px 16px;border-radius:12px;background:var(--color-secondary);border:1px solid var(--color-border)}
.nm-cost-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13px}
.nm-cost-head b{font-size:18px}
.nm-cost-bar{display:flex;height:14px;border-radius:7px;overflow:hidden;margin:10px 0 8px;background:var(--color-border)}
.nm-cost-bar i{display:block;height:100%}
.nm-cost-legend{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12px;color:var(--color-text-secondary)}
.nm-cost-legend span::before{content:'';display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;background:var(--k);vertical-align:-1px}
@media (max-width:760px){.nm-flow{grid-template-columns:1fr}.nm-flow .op{display:none}}
.nm-run-table td{white-space:nowrap;font-variant-numeric:tabular-nums}
.nm-run-table thead tr.grp th{padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;text-align:center;color:#fff;border-bottom:3px solid var(--g,transparent)}
</style>
<div style="margin-bottom:12px;"><a class="btn btn-secondary" href="<?php echo $base; ?>?tab=runs">← Volver</a></div>
<div class="card neumorphic" style="margin-bottom:16px;">
    <div class="card-body">
        <div class="nm-run-head">
            <div>
                <h3><?php echo htmlspecialchars($periodName); ?> <small><?php echo htmlspecialchars($run['run_number']); ?></small>
                    <span class="nm-status <?php echo $stTone; ?>"><?php echo htmlspecialchars($stName); ?></span></h3>
                <div class="nm-run-meta">
                    <span class="nm-pill">Pago: <b><?php echo date('d/m/Y', strtotime($run['pay_date'])); ?></b></span>
                    <?php if ($accrualDate !== (string)$run['pay_date']): ?>
                        <span class="nm-pill">Causación: <b><?php echo date('d/m/Y', strtotime($accrualDate)); ?></b> <?php echo nmTip('El pago cae fuera del mes liquidado: el costo se registra el último día del mes y el pago en su fecha (salarios por pagar 250505).'); ?></span>
                    <?php endif; ?>
                    <span class="nm-pill"><b><?php echo (int)$run['days_worked']; ?></b> días</span>
                    <span class="nm-pill"><b><?php echo count($items); ?></b> empleado(s)</span>
                    <span class="nm-pill"><?php echo htmlspecialchars(\SoftNova\Core\payment_method_label((string)($run['payment_method'] ?? ''))); ?></span>
                    <span style="align-self:center;margin-left:6px;">Incluye:</span>
                    <?php foreach ($includes as [$incLabel, $incOn]): ?>
                        <span class="nm-inc <?php echo $incOn ? 'on' : 'no'; ?>"><?php echo $incOn ? '✓ ' : ''; ?><?php echo $incLabel; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <?php if (!empty($accountingEntry['id'])): ?>
                    <a class="btn btn-sm btn-info" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=entries&amp;entry_id=<?php echo (int)$accountingEntry['id']; ?>">
                        Asiento <?php echo htmlspecialchars((string)($accountingEntry['entry_number'] ?? '')); ?>
                    </a>
                <?php endif; ?>
                <?php if (!empty($paymentEntry['id'])): ?>
                    <a class="btn btn-sm btn-info" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=entries&amp;entry_id=<?php echo (int)$paymentEntry['id']; ?>" title="Asiento del pago (fecha de pago)">
                        Pago <?php echo htmlspecialchars((string)($paymentEntry['entry_number'] ?? '')); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($stHint !== ''): ?>
            <div class="nm-run-banner <?php echo $stTone === 'warn' ? 'warn' : ''; ?>"><?php echo htmlspecialchars($stHint); ?></div>
        <?php endif; ?>

        <div class="nm-flow">
            <div class="nm-kpi" style="--k:#0D7C4A"><span>Total devengado</span><b><?php echo fmtN((float)$run['gross_total'], $currency); ?></b></div>
            <div class="op">−</div>
            <div class="nm-kpi" style="--k:#F59E0B"><span>Deducciones al empleado</span><b><?php echo fmtN((float)$run['deductions_total'], $currency); ?></b></div>
            <div class="op">=</div>
            <div class="nm-kpi main" style="--k:#0D7C4A"><span>Neto a pagar a empleados</span><b><?php echo fmtN((float)$run['net_total'], $currency); ?></b></div>
        </div>

        <div class="nm-seg-grid">
            <?php foreach ($runSections as [$segTitle, $segDesc, $segColor, $segRows, $segTotal, $segTotalLabel]): ?>
                <section class="nm-seg" style="--k:<?php echo $segColor; ?>">
                    <h4><?php echo htmlspecialchars($segTitle); ?></h4>
                    <p><?php echo htmlspecialchars($segDesc); ?></p>
                    <?php foreach ($segRows as [$rowLabel, $rowAmt, $rowTip]):
                        $pct = $segTotal > 0 ? max(0, min(100, $rowAmt / $segTotal * 100)) : 0; ?>
                        <div class="nm-seg-row <?php echo $rowAmt > 0 ? '' : 'zero'; ?>">
                            <span><?php echo htmlspecialchars($rowLabel); ?><?php echo $rowTip ? nmTip($rowTip) : ''; ?></span>
                            <span class="amt"><?php echo fmtN($rowAmt, $currency); ?></span>
                            <?php if ($rowAmt > 0): ?><span class="bar"><i style="width:<?php echo round($pct, 1); ?>%"></i></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="nm-seg-total"><span><?php echo htmlspecialchars($segTotalLabel); ?></span><b><?php echo fmtN($segTotal, $currency); ?></b></div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="nm-cost">
            <div class="nm-cost-head">
                <span>Costo total para la empresa <?php echo nmTip('Devengado + aportes de la empresa + provisiones. Muestra a dónde va cada peso de la nómina.'); ?></span>
                <b><?php echo fmtN($companyCost, $currency); ?></b>
            </div>
            <div class="nm-cost-bar">
                <?php foreach ($costParts as [$cpLabel, $cpAmt, $cpColor]): ?>
                    <?php if ($companyCost > 0 && $cpAmt > 0): ?><i style="width:<?php echo round($cpAmt / $companyCost * 100, 2); ?>%;background:<?php echo $cpColor; ?>" title="<?php echo htmlspecialchars($cpLabel); ?>"></i><?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="nm-cost-legend">
                <?php foreach ($costParts as [$cpLabel, $cpAmt, $cpColor]): ?>
                    <span style="--k:<?php echo $cpColor; ?>"><?php echo htmlspecialchars($cpLabel); ?>: <b><?php echo fmtN($cpAmt, $currency); ?></b>
                        (<?php echo $companyCost > 0 ? number_format($cpAmt / $companyCost * 100, 1, ',', '.') : '0'; ?> %)</span>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <a class="btn btn-secondary" target="_blank" href="<?php echo $base; ?>?action=pdf&amp;id=<?php echo (int)$run['id']; ?>">PDF liquidación</a>
        <?php if (($run['status'] ?? '') === 'draft' && $canEdit): ?>
                <form method="POST" action="<?php echo $base; ?>?action=pay_run" data-ajax="true" style="display:inline;">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$run['id']; ?>">
                    <button class="btn btn-primary" type="submit" onclick="return confirm('¿Contabilizar nómina y marcar como pagada?\n\nSe registra el gasto por el costo total de la empresa (<?php echo htmlspecialchars(fmtN($companyCost, $currency), ENT_QUOTES); ?>) con fecha <?php echo date('d/m/Y', strtotime($accrualDate)); ?>. No descuenta el cajón de caja.')">Contabilizar y pagar</button>
                </form>
                <form method="POST" action="<?php echo $base; ?>?action=cancel_run" data-ajax="true" style="display:inline;">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$run['id']; ?>">
                    <button class="btn btn-danger" type="submit" onclick="return confirm('¿Cancelar liquidación?')">Cancelar</button>
                </form>
        <?php endif; ?>
        <?php if (in_array(($run['status'] ?? ''), ['paid', 'posted'], true) && $canDelete): ?>
                <form method="POST" action="<?php echo $base; ?>?action=cancel_paid_run" data-ajax="true" class="nm-void-form">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$run['id']; ?>">
                    <input type="text" name="reason" class="form-control" required minlength="5" maxlength="200" placeholder="Motivo de la anulación (obligatorio)">
                    <button class="btn btn-danger" type="submit" data-confirm="¿Anular el pago de esta nómina? Se revierten sus asientos contables, se anula el gasto en Gastos y la liquidación vuelve a borrador para corregirla. Si ya registró pagos de seguridad social de este periodo, revíselos en «Pagos de nómina».">Anular pago</button>
                </form>
        <?php endif; ?>
        </div>
    </div>
</div>
<div class="card neumorphic">
    <div class="card-body"><div class="table-container"><table class="nm-run-table">
        <thead>
            <tr class="grp">
                <th colspan="3"></th>
                <th colspan="5" style="--g:#34D399">Devengado</th>
                <th colspan="2" style="--g:#FBBF24">Deducciones</th>
                <th></th>
                <th style="--g:#38BDF8">No afecta neto</th>
                <th></th>
            </tr>
            <tr><th>Empleado</th><th>Días</th><th>Inc.</th>
                <th>Salario + aux. <?php echo nmTip('Salario de los días trabajados + auxilio de transporte + pago de incapacidad.'); ?></th><th>HE / rec.</th><th>Comisión</th><th>Prima</th><th>Total</th>
                <th>Salud</th><th>Pensión</th><th>Neto</th>
                <th>Provisiones <?php echo nmTip('Cesantías + intereses + vacaciones de este empleado.'); ?></th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <?php
            $otTotal = (float)($it['other_earnings'] ?? 0);
            $commissionAmt = (float)($it['commission_amount'] ?? 0);
            $primaAmt = (float)($it['prima'] ?? 0);
            $basePay = (float)$it['salary_base'] + (float)($it['transport_aid'] ?? 0) + (float)($it['incapacity_pay'] ?? 0);
            $provAmt = (float)($it['cesantias'] ?? 0) + (float)($it['cesantias_interest'] ?? 0) + (float)($it['vacation_provision'] ?? 0);
            ?>
            <tr>
                <td><?php echo htmlspecialchars($it['employee_name']); ?></td>
                <td><?php echo (int)$it['days_worked']; ?></td>
                <td><?php echo (int)($it['incapacity_days'] ?? 0) ?: '-'; ?></td>
                <td><?php echo fmtN($basePay, $currency); ?></td>
                <td><?php echo $otTotal > 0 ? fmtN($otTotal, $currency) : '-'; ?></td>
                <td><?php echo $commissionAmt > 0 ? fmtN($commissionAmt, $currency) : '-'; ?></td>
                <td><?php echo $primaAmt > 0 ? fmtN($primaAmt, $currency) : '-'; ?></td>
                <td><strong><?php echo fmtN((float)($it['gross_pay'] ?? 0), $currency); ?></strong></td>
                <td><?php echo fmtN((float)$it['health_employee'], $currency); ?></td>
                <td><?php echo fmtN((float)$it['pension_employee'], $currency); ?></td>
                <td><strong style="color:var(--color-accent-text, var(--color-primary));"><?php echo fmtN((float)$it['net_pay'], $currency); ?></strong></td>
                <td style="color:var(--color-text-secondary);"><?php echo $provAmt > 0 ? fmtN($provAmt, $currency) : '-'; ?></td>
                <td style="white-space:nowrap;">
                    <a class="btn btn-sm btn-secondary" target="_blank" href="<?php echo $base; ?>?action=payslip&amp;run_id=<?php echo (int)$run['id']; ?>&amp;item_id=<?php echo (int)$it['id']; ?>">PDF</a>
                    <?php if (($run['status'] ?? '') === 'draft' && $canEdit): ?>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="editIncapacity(<?php echo (int)$it['id']; ?>,<?php echo (int)$run['id']; ?>,<?php echo (int)($it['incapacity_days'] ?? 0); ?>,'<?php echo htmlspecialchars($it['incapacity_type'] ?? 'enfermedad', ENT_QUOTES); ?>')">Inc.</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="editOvertime(<?php echo (int)$it['id']; ?>,<?php echo (int)$run['id']; ?>,<?php echo json_encode([
                            'day' => (float)($it['overtime_day_hours'] ?? 0),
                            'night' => (float)($it['overtime_night_hours'] ?? 0),
                            'rno' => (float)($it['night_surcharge_hours'] ?? 0),
                            'dom' => (float)($it['dominical_hours'] ?? 0),
                            'hedd' => (float)($it['overtime_dom_day_hours'] ?? 0),
                            'hend' => (float)($it['overtime_dom_night_hours'] ?? 0),
                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)">HE</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (($run['status'] ?? '') === 'draft' && $canEdit && $items): ?>
    <?php
    $otCols = [
        'day' => ['HED', 'overtime_day_hours', 'Hora extra diurna: +' . (float)($params['overtime_day_rate'] ?? 25) . '% sobre la hora ordinaria.'],
        'night' => ['HEN', 'overtime_night_hours', 'Hora extra nocturna: +' . (float)($params['overtime_night_rate'] ?? 75) . '%.'],
        'rno' => ['RNO', 'night_surcharge_hours', 'Recargo nocturno ordinario: solo el ' . (float)($params['night_surcharge_rate'] ?? 35) . '% adicional por horas de la jornada normal en horario nocturno.'],
        'dom' => ['Dom.', 'dominical_hours', 'Dominical/festivo ordinario: hora + ' . (float)($params['dominical_rate'] ?? 90) . '%.'],
        'hedd' => ['HEDD', 'overtime_dom_day_hours', 'Hora extra dominical diurna: +' . (float)($params['overtime_dom_day_rate'] ?? 115) . '%.'],
        'hend' => ['HEND', 'overtime_dom_night_hours', 'Hora extra dominical nocturna: +' . (float)($params['overtime_dom_night_rate'] ?? 165) . '%.'],
    ];
    ?>
    <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--color-border);">
        <h4 style="margin:0 0 8px;">Captura masiva de horas extras <?php echo nmTip('Digite las horas del periodo por empleado. Solo se recalculan las filas que cambian. Valor hora = salario ÷ ' . (float)($params['payroll_monthly_hours'] ?? 210) . ' h.'); ?></h4>
        <form method="POST" action="<?php echo $base; ?>?action=save_overtime_bulk" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="run_id" value="<?php echo (int)$run['id']; ?>">
            <div class="table-container" style="max-height:420px;overflow:auto;">
                <table>
                    <thead>
                    <tr>
                        <th>Empleado</th>
                        <?php foreach ($otCols as [$lbl, , $tipText]): ?>
                            <th style="white-space:nowrap;"><?php echo $lbl; ?><?php echo nmTip($tipText); ?></th>
                        <?php endforeach; ?>
                        <th>Valor HE</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <?php $iid = (int)$it['id']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($it['employee_name']); ?></td>
                            <?php foreach ($otCols as $key => [, $col]): ?>
                                <td><input class="form-control" style="min-width:64px;width:72px;" type="number" min="0" step="0.25" name="ot[<?php echo $iid; ?>][<?php echo $key; ?>]" value="<?php echo htmlspecialchars((string)(float)($it[$col] ?? 0)); ?>"></td>
                            <?php endforeach; ?>
                            <td><?php echo (float)($it['other_earnings'] ?? 0) > 0 ? fmtN((float)$it['other_earnings'], $currency) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Recalcular horas extras</button>
        </form>
    </div>
    <?php endif; ?>
    <p class="nm-footnote">
        En borrador ajuste incapacidad y horas extras por empleado o en la tabla masiva.
        Cesantías y vacaciones (si se incluyen) son provisiones contables (261005 y 261015) y <strong>no aumentan el neto a pagar</strong>.
        Al pagar se genera asiento (sueldos, HE, comisiones, aportes, parafiscales y provisiones) y queda en auditoría.
        <strong>La nómina no descuenta el cajón físico de Caja/POS</strong>.
    </p>
    </div>
</div>

<?php elseif ($tab === 'payments'): ?>
<?php
$liabilities = $liabilities ?? [];
$liabilityPayments = $liabilityPayments ?? [];
$conceptDefs = \SoftNova\Services\PayrollService::LIABILITY_CONCEPTS;
$kindLabels = ['pila' => 'Seguridad social y parafiscales (PILA)', 'benefits' => 'Prestaciones y salarios por pagar'];
$moneyPlain = static function (float $v): string {
    $v = round($v, 2);
    return abs($v - round($v)) < 0.005
        ? number_format($v, 0, ',', '.')
        : number_format($v, 2, ',', '.');
};
$byKind = ['pila' => [], 'benefits' => []];
foreach ($liabilities as $li) {
    $byKind[$li['kind']][] = $li;
}
?>
<style>
.nm-liab-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;margin-bottom:16px}
.nm-liab-row{display:grid;grid-template-columns:1fr auto 170px;gap:10px;align-items:center;padding:7px 0;border-bottom:1px dashed var(--color-border);font-size:13px}
.nm-liab-row .acc{font-size:11px;color:var(--color-text-secondary)}
.nm-liab-row .bal{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.nm-liab-row .bal.neg{color:var(--dmc-B45309, #B45309)}
.nm-liab-row .bal.zero{color:var(--color-text-secondary)}
.nm-liab-foot{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}
.nm-liab-total{display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-weight:700}
.nm-liab-lines{margin:0;padding-left:16px;font-size:12px}
@media (max-width:560px){.nm-liab-grid{grid-template-columns:1fr}.nm-liab-row{grid-template-columns:1fr 130px}.nm-liab-row .bal{grid-column:1/-1;text-align:left}}
</style>
<div class="nm-run-banner" style="margin:0 0 16px;">
    Aquí se registran los pagos de lo que la nómina dejó <strong>por pagar</strong>: planilla PILA (salud, pensión, ARL, caja, SENA, ICBF),
    consignación de cesantías, intereses, vacaciones pagadas y salarios pendientes.
    El pago <strong>no genera gasto</strong> (el costo ya se registró al liquidar la nómina): solo baja el pasivo contra banco o caja de nómina.
    No registre estos pagos en Gastos porque el costo quedaría duplicado.
</div>
<div class="nm-liab-grid">
<?php foreach (['pila', 'benefits'] as $kindKey): ?>
    <div class="card neumorphic" style="margin:0;">
        <div class="card-header"><h3><?php echo htmlspecialchars($kindLabels[$kindKey]); ?></h3></div>
        <div class="card-body">
        <form method="POST" action="<?php echo $base; ?>?action=save_liability_payment" data-ajax="true" class="js-liab-form">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="kind" value="<?php echo $kindKey; ?>">
            <div class="nm-liab-row" style="font-size:11px;text-transform:uppercase;color:var(--color-text-secondary);border-bottom-style:solid;">
                <span>Concepto</span><span class="bal">Saldo por pagar</span><span>Valor a pagar</span>
            </div>
            <?php foreach ($byKind[$kindKey] as $li):
                $bal = (float)$li['balance'];
                $balClass = $bal < -0.004 ? 'neg' : (abs($bal) < 0.005 ? 'zero' : ''); ?>
                <div class="nm-liab-row">
                    <span><?php echo htmlspecialchars($li['label']); ?><br><span class="acc">Cuenta <?php echo htmlspecialchars($li['account']); ?></span></span>
                    <span class="bal <?php echo $balClass; ?>" title="<?php echo $bal < -0.004 ? 'Saldo a favor: se ha pagado más de lo causado' : ''; ?>"><?php echo fmtN($bal, $currency); ?></span>
                    <span>
                        <?php if ($canEdit): ?>
                        <input type="text" inputmode="decimal" class="form-control money-input js-liab-amount" data-money="1"
                               name="amounts[<?php echo htmlspecialchars($li['concept']); ?>]"
                               data-balance="<?php echo htmlspecialchars($moneyPlain(max(0, $bal))); ?>" placeholder="0">
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
            <div class="nm-liab-total"><span>Total a pagar</span><span class="js-liab-total">0</span></div>
            <div class="nm-liab-foot">
                <div class="form-group" style="margin:0;"><label>Fecha de pago</label><input class="form-control" type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="form-group" style="margin:0;"><label>Medio de pago</label><select class="form-control" name="payment_method"><?php echo \SoftNova\Core\payment_method_options('transfer'); ?></select></div>
                <div class="form-group" style="margin:0;"><label>Referencia</label><input class="form-control" name="reference" maxlength="120" placeholder="<?php echo $kindKey === 'pila' ? 'Nº planilla PILA' : 'Fondo / soporte'; ?>"></div>
            </div>
            <div class="form-group" style="margin:10px 0 0;"><label>Notas</label><input class="form-control" name="notes" maxlength="255"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary js-liab-fill" title="Llena cada concepto con su saldo por pagar">Usar saldos</button>
                <button type="submit" class="btn btn-primary" data-confirm="¿Registrar este pago? Se contabiliza contra banco (medios electrónicos) o caja de nómina 110510 (efectivo). No descuenta el cajón POS.">Registrar pago</button>
            </div>
            <?php endif; ?>
        </form>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Historial de pagos de nómina</h3></div>
    <div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Conceptos</th><th>Total</th><th>Medio</th><th>Referencia</th><th>Asiento</th><th>Estado</th><?php if ($canDelete): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$liabilityPayments): ?>
            <tr><td colspan="<?php echo $canDelete ? 9 : 8; ?>" class="dashboard-empty">Aún no hay pagos registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($liabilityPayments as $p):
            $isCancelled = ($p['status'] ?? '') === 'cancelled'; ?>
            <tr style="<?php echo $isCancelled ? 'opacity:.6;' : ''; ?>">
                <td><?php echo date('d/m/Y', strtotime((string)$p['payment_date'])); ?></td>
                <td><?php echo htmlspecialchars($p['kind'] === 'pila' ? 'PILA' : 'Prestaciones'); ?></td>
                <td><ul class="nm-liab-lines">
                    <?php foreach ($p['lines'] as $pl): ?>
                        <li><?php echo htmlspecialchars($conceptDefs[$pl['concept']]['label'] ?? $pl['concept']); ?>: <?php echo fmtN((float)$pl['amount'], $currency); ?></li>
                    <?php endforeach; ?>
                </ul></td>
                <td><strong><?php echo fmtN((float)$p['total'], $currency); ?></strong></td>
                <td><?php echo htmlspecialchars(\SoftNova\Core\payment_method_label((string)($p['payment_method'] ?? ''))); ?></td>
                <td><?php echo htmlspecialchars((string)($p['reference'] ?? '')); ?></td>
                <td>
                    <?php if (!empty($p['entry_id'])): ?>
                        <a class="btn btn-sm btn-info" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=entries&amp;entry_id=<?php echo (int)$p['entry_id']; ?>"><?php echo htmlspecialchars((string)($p['entry_number'] ?? 'Ver')); ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($isCancelled): ?>
                        <span class="badge" title="<?php echo htmlspecialchars((string)($p['cancel_reason'] ?? '')); ?>">Anulado</span>
                    <?php else: ?>
                        <span class="badge badge-success">Registrado</span>
                    <?php endif; ?>
                </td>
                <?php if ($canDelete): ?>
                <td>
                    <?php if (!$isCancelled): ?>
                    <form method="POST" action="<?php echo $base; ?>?action=cancel_liability_payment" data-ajax="true" class="nm-void-form">
                        <?php echo \SoftNova\Core\csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                        <input type="text" name="reason" class="form-control" required minlength="5" maxlength="200" placeholder="Motivo">
                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="¿Anular este pago? Se revierte el asiento y el saldo vuelve a quedar por pagar.">Anular</button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<script>
(function () {
    function parseAmt(v) {
        if (window.SeriMoney && typeof SeriMoney.parse === 'function') return SeriMoney.parse(v) || 0;
        var s = String(v || '').replace(/\s/g, '');
        if (s.indexOf(',') > -1) s = s.replace(/\./g, '').replace(',', '.');
        else if ((s.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(s)) s = s.replace(/\./g, '');
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }
    function fmt(n) {
        return n.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }
    document.querySelectorAll('.js-liab-form').forEach(function (form) {
        var totalEl = form.querySelector('.js-liab-total');
        function recalc() {
            var t = 0;
            form.querySelectorAll('.js-liab-amount').forEach(function (inp) { t += parseAmt(inp.value); });
            if (totalEl) totalEl.textContent = fmt(Math.round(t * 100) / 100);
        }
        form.addEventListener('input', recalc);
        form.addEventListener('change', recalc);
        var fill = form.querySelector('.js-liab-fill');
        if (fill) fill.addEventListener('click', function () {
            form.querySelectorAll('.js-liab-amount').forEach(function (inp) {
                var b = inp.getAttribute('data-balance') || '';
                inp.value = (b === '0' || b === '') ? '' : b;
            });
            recalc();
        });
        recalc();
    });
})();
</script>

<?php elseif ($tab === 'params'): ?>
<?php
$paramGroups = [
    ['title' => 'Salario base', 'desc' => 'Valores de referencia del año.', 'acc' => '#0D7C4A', 'preview' => 'base', 'fields' => [
        ['smmlv', 'SMMLV', 'money', 'Salario Mínimo Mensual Legal Vigente. Define el tope del auxilio de transporte (hasta 2 SMMLV). Valor 2026: $1.750.905.'],
        ['transport_aid', 'Auxilio de transporte', 'money', 'Se paga proporcional a los días trabajados a quienes ganan hasta 2 SMMLV y tienen la casilla activa. No es base de aportes. Valor 2026: $249.095.'],
    ]],
    ['title' => 'Jornada laboral', 'desc' => 'Base del valor hora y de la validación de horarios.', 'acc' => '#6366F1', 'preview' => 'hour', 'fields' => [
        ['payroll_monthly_hours', 'Horas mensuales', 'h', 'Divisor del valor hora: salario ÷ horas mensuales. Con jornada de 42 h/semana (desde 15-jul-2026) son 210 h/mes.'],
        ['max_weekly_hours', 'Jornada máx. semanal', 'h', 'Límite legal semanal (Ley 2101: 42 h desde jul-2026). Se usa para alertar en Horarios cuando un turno lo supera.'],
        ['max_daily_hours', 'Jornada máx. diaria', 'h', 'Horas ordinarias por día. Si un día del horario del empleado (descontando el descanso) supera este valor, el exceso cuenta como hora extra: diurna o nocturna según la ventana nocturna, y dominical si es domingo. Se puede precargar al crear la liquidación.'],
        ['night_start_hour', 'Inicio nocturno', 'hour', 'Hora (0-23) en que empieza el trabajo nocturno. Desde el 25-dic-2025 es a las 19:00 (Ley 2466 de 2025).'],
        ['night_end_hour', 'Fin nocturno', 'hour', 'Hora (0-23) en que termina el trabajo nocturno. Normalmente 6:00.'],
    ]],
    ['title' => 'Aportes del empleado', 'desc' => 'Se descuentan del devengado sobre el IBC (salario + HE/recargos, sin auxilio).', 'acc' => '#F59E0B', 'preview' => 'emp', 'fields' => [
        ['health_employee_rate', 'Salud', 'pct', 'Descuento de salud (EPS) al empleado. Normal: 4%.'],
        ['pension_employee_rate', 'Pensión', 'pct', 'Descuento de pensión al empleado. Normal: 4%.'],
    ]],
    ['title' => 'Aportes del empleador', 'desc' => 'Costo de la empresa; no afecta el neto del empleado.', 'acc' => '#EF4444', 'preview' => 'er', 'fields' => [
        ['health_employer_rate', 'Salud', 'pct', 'Aporte de salud del empleador: 8,5%. Puede ser 0% si la empresa aplica la exoneración del art. 114-1 E.T. (salarios menores a 10 SMMLV).'],
        ['pension_employer_rate', 'Pensión', 'pct', 'Aporte de pensión del empleador: 12%.'],
        ['arl_employer_rate', 'ARL', 'pct', 'Según clase de riesgo: I 0,522% · II 1,044% · III 2,436% · IV 4,350% · V 6,960%. Se calcula siempre.'],
    ]],
    ['title' => 'Parafiscales', 'desc' => 'Solo se liquidan si la liquidación tiene marcada la opción «Parafiscales».', 'acc' => '#8B5CF6', 'preview' => 'para', 'fields' => [
        ['caja_employer_rate', 'Caja compensación', 'pct', 'Caja de Compensación Familiar: 4%.'],
        ['sena_employer_rate', 'SENA', 'pct', 'SENA: 2%. Use 0% si la empresa aplica la exoneración del art. 114-1 E.T.'],
        ['icbf_employer_rate', 'ICBF', 'pct', 'ICBF: 3%. Use 0% si la empresa aplica la exoneración del art. 114-1 E.T.'],
    ]],
    ['title' => 'Incapacidades', 'desc' => 'Pago de días de incapacidad por enfermedad general.', 'acc' => '#06B6D4', 'preview' => null, 'fields' => [
        ['incapacity_rate', '% desde el día 3', 'pct', 'Los días 1 y 2 los paga el empleador al 100%. Desde el día 3 se paga este porcentaje (EPS: 66,67%). La incapacidad laboral (ARL) siempre se paga al 100%.'],
    ]],
    ['title' => 'Provisiones', 'desc' => 'Prestaciones sociales como % mensual. Cesantías y prima se calculan sobre salario + auxilio de transporte (si aplica); vacaciones solo sobre el salario. Se aplican según las casillas de la nueva liquidación.', 'acc' => '#0EA5E9', 'preview' => 'prov', 'fields' => [
        ['cesantias_rate', 'Cesantías', 'pct', 'Provisión mensual de cesantías sobre salario + auxilio de transporte: 8,33% (un mes por año). Se contabiliza en 261005 y no suma al neto a pagar.'],
        ['cesantias_interest_rate', 'Intereses s/ cesantías', 'pct', 'Porcentaje ANUAL sobre las cesantías: 12%. Cada mes se provisiona la doceava parte (≈1% de las cesantías).'],
        ['prima_rate', 'Prima de servicios', 'pct', 'Equivalente mensual de la prima: 8,33% sobre salario + auxilio de transporte. Cuando la liquidación incluye prima (junio y diciembre) se paga este % × 6 meses (medio mes) y sí suma al neto.'],
        ['vacation_rate', 'Vacaciones', 'pct', 'Provisión mensual de vacaciones: 4,17% (15 días hábiles por año), solo sobre el salario. Se contabiliza en 510539 contra 261015 y no suma al neto a pagar.'],
        ['prestaciones_include_transport', 'Auxilio de transporte en base de cesantías y prima', 'toggle', 'Por ley (Ley 1 de 1963, art. 7) el auxilio de transporte se suma a la base de cesantías, intereses y prima de quienes lo reciben (salario hasta 2 SMMLV y casilla activa en el empleado). Se suma el auxilio mensual completo. Desmarque solo si su contador lo indica.'],
    ]],
    ['title' => 'Horas extras y recargos', 'desc' => 'Porcentaje adicional sobre el valor hora ordinario.', 'acc' => '#EC4899', 'preview' => 'ot', 'wide' => true, 'fields' => [
        ['overtime_day_rate', 'HE diurna (HED)', 'pct', 'Hora extra diurna: hora ordinaria + 25%.'],
        ['overtime_night_rate', 'HE nocturna (HEN)', 'pct', 'Hora extra nocturna: hora ordinaria + 75%.'],
        ['night_surcharge_rate', 'Recargo nocturno (RNO)', 'pct', 'Recargo nocturno ordinario: solo el 35% adicional por horas de la jornada normal trabajadas en horario nocturno. Se puede precargar desde el horario del empleado.'],
        ['dominical_rate', 'Dominical / festivo', 'pct', 'Recargo por trabajar domingo o festivo (Ley 2466): 80% desde jul-2025, 90% desde jul-2026, 100% desde jul-2027.'],
        ['overtime_dom_day_rate', 'HE dominical diurna (HEDD)', 'pct', 'Hora extra en domingo/festivo diurna = 25% de HE + recargo dominical (2026: 115%).'],
        ['overtime_dom_night_rate', 'HE dominical nocturna (HEND)', 'pct', 'Hora extra en domingo/festivo nocturna = 75% de HE + recargo dominical (2026: 165%).'],
    ]],
];
$suggestKeys = ['smmlv', 'transport_aid', 'payroll_monthly_hours', 'max_weekly_hours', 'max_daily_hours', 'night_start_hour', 'night_end_hour',
    'cesantias_rate', 'cesantias_interest_rate', 'prima_rate', 'vacation_rate',
    'overtime_day_rate', 'overtime_night_rate', 'night_surcharge_rate', 'dominical_rate', 'overtime_dom_day_rate', 'overtime_dom_night_rate'];
$suggested = array_intersect_key(\SoftNova\Services\PayrollService::PARAM_DEFAULTS, array_flip($suggestKeys));
$decimalRates = \SoftNova\Services\PayrollService::DECIMAL_RATE_KEYS;
$fmtParam = static function ($v, string $key, string $kind) use ($decimalRates): string {
    if ($kind === 'money' || ($kind === 'pct' && !in_array($key, $decimalRates, true))) {
        return (string)(int)round((float)$v);
    }
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
};
$commissionEmployees = array_values(array_filter($employees ?? [], static fn ($e) => ($e['status'] ?? 'active') === 'active'));
?>
<div class="nm-section-head">
    <div>
        <h3>Parámetros de liquidación (Colombia)</h3>
        <p>Agrupados por concepto. Pase el cursor por <span class="nm-tip" style="cursor:default;">i</span> para ver la explicación de cada valor. La liquidación usa base de 30 días.</p>
    </div>
</div>
<form method="POST" action="<?php echo $base; ?>?action=save_params" data-ajax="true" id="paramsForm">
    <?php echo \SoftNova\Core\csrf_field(); ?>
    <div class="nm-param-grid">
        <?php foreach ($paramGroups as $gi => $g): ?>
            <section class="nm-group <?php echo !empty($g['wide']) ? 'wide' : ''; ?>" style="--acc:<?php echo $g['acc']; ?>">
                <div class="nm-group-head">
                    <h4><span class="nm-num"><?php echo $gi + 1; ?></span><?php echo htmlspecialchars($g['title']); ?></h4>
                    <p><?php echo htmlspecialchars($g['desc']); ?></p>
                </div>
                <div class="nm-fields">
                    <?php foreach ($g['fields'] as [$key, $label, $kind, $tipText]): ?>
                        <?php if ($kind === 'toggle'): ?>
                        <div class="nm-field" style="grid-column:1/-1;">
                            <input type="hidden" name="<?php echo $key; ?>" value="0">
                            <label class="nm-check" for="p_<?php echo $key; ?>">
                                <input type="checkbox" id="p_<?php echo $key; ?>" name="<?php echo $key; ?>" value="1" data-toggle-param
                                       <?php echo !empty((float)($params[$key] ?? 0)) ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
                                <?php echo htmlspecialchars($label); ?><?php echo nmTip($tipText); ?>
                            </label>
                        </div>
                        <?php continue; endif; ?>
                        <?php
                        $isDecimalRate = in_array($key, $decimalRates, true);
                        $step = match ($kind) { 'money' => '1', 'hour' => '1', 'h' => '0.5', default => ($key === 'arl_employer_rate' ? '0.001' : ($isDecimalRate ? '0.01' : '1')) };
                        $affix = match ($kind) { 'money' => ['prefix', '$'], 'pct' => ['suffix', '%'], 'h' => ['suffix', 'h'], 'hour' => ['suffix', ':00'], default => ['', ''] };
                        $max = match ($kind) { 'hour' => ' max="23"', 'pct' => ' max="400"', default => '' };
                        $pVal = $fmtParam($params[$key] ?? 0, $key, $kind);
                        if ($kind === 'pct' && $isDecimalRate) {
                            $tipText .= ' Admite decimales porque el valor legal los lleva.';
                        }
                        ?>
                        <div class="nm-field">
                            <label for="p_<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?><?php echo nmTip($tipText); ?></label>
                            <div class="nm-input <?php echo $affix[0]; ?>">
                                <?php if ($affix[0] === 'prefix'): ?><span class="nm-affix"><?php echo $affix[1]; ?></span><?php endif; ?>
                                <?php if ($kind === 'money'): ?>
                                <input class="form-control" type="text" inputmode="numeric" id="p_<?php echo $key; ?>" name="<?php echo $key; ?>" data-money="1"
                                       value="<?php echo htmlspecialchars($canEdit ? $pVal : number_format((float)$pVal, 0, ',', '.')); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> data-param>
                                <?php else: ?>
                                <input class="form-control" type="number" id="p_<?php echo $key; ?>" name="<?php echo $key; ?>" min="0"<?php echo $max; ?> step="<?php echo $step; ?>"
                                       value="<?php echo htmlspecialchars($pVal); ?>" <?php echo $canEdit ? '' : 'disabled'; ?> data-param>
                                <?php endif; ?>
                                <?php if ($affix[0] === 'suffix'): ?><span class="nm-affix"><?php echo $affix[1]; ?></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($g['preview']): ?>
                    <div class="nm-preview" data-preview="<?php echo $g['preview']; ?>"></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <section class="nm-group wide" style="--acc:#14B8A6">
            <div class="nm-group-head">
                <h4><span class="nm-num"><?php echo count($paramGroups) + 1; ?></span>Comisiones fijas</h4>
                <p>Valor fijo por quincena para cada empleado activo. Una liquidación mensual (más de 15 días) paga 2 quincenas; una de 15 días o menos, 1. Se suma al neto como pago no salarial: no es base de salud, pensión, ARL, parafiscales ni prestaciones.</p>
            </div>
            <?php if ($commissionEmployees === []): ?>
                <p style="margin:0;font-size:13px;color:var(--color-text-secondary);">No hay empleados activos. Créelos en la pestaña Empleados.</p>
            <?php else: ?>
            <div class="table-container" style="max-height:360px;overflow:auto;">
                <table class="nm-commission-table">
                    <thead><tr>
                        <th>Empleado</th>
                        <th>Cargo</th>
                        <th style="text-align:right;">Salario</th>
                        <th style="min-width:200px;">Comisión fija quincenal <?php echo nmTip('Valor que se paga en cada quincena. En la liquidación mensual (30 días) se pagan 2 quincenas; si el periodo es de 15 días o menos, 1. Deje 0 si el empleado no tiene comisión fija. Al guardar se actualizan las liquidaciones en borrador.'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($commissionEmployees as $ce):
                        $ceName = trim(($ce['first_name'] ?? '') . ' ' . ($ce['last_name'] ?? '')) ?: (string)($ce['name'] ?? ('#' . (int)$ce['id']));
                        $ceVal = (float)($ce['fixed_commission'] ?? 0); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ceName); ?></td>
                            <td><?php echo htmlspecialchars((string)($ce['position_title'] ?? '') ?: '—'); ?></td>
                            <td style="text-align:right;white-space:nowrap;"><?php echo fmtN((float)($ce['salary'] ?? 0), $currency); ?></td>
                            <td>
                                <div class="nm-input prefix">
                                    <span class="nm-affix">$</span>
                                    <input class="form-control" type="text" inputmode="numeric" data-money="1" data-commission
                                           name="fixed_commission[<?php echo (int)$ce['id']; ?>]"
                                           value="<?php echo htmlspecialchars($canEdit ? (string)(int)round($ceVal) : number_format($ceVal, 0, ',', '.')); ?>" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="nm-preview" data-preview="com"></div>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($canEdit): ?>
    <div class="nm-savebar">
        <span class="nm-note" id="paramsNote">Los cambios aplican a las liquidaciones nuevas y a los recálculos de borradores.</span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-secondary" onclick="nmSuggestParams()">Sugerir valores legales 2026 <?php echo nmTip('Rellena SMMLV, auxilio, jornada, ventana nocturna y recargos con los valores legales de 2026. No toca los % de aportes ni parafiscales. Revise y pulse Guardar.'); ?></button>
            <button class="btn btn-primary" type="submit">Guardar parámetros</button>
        </div>
    </div>
    <?php endif; ?>
</form>
<p class="nm-footnote">
    Prima ≈ medio mes (salario + auxilio de transporte) por semestre. Cesantías = provisión mensual (salario + auxilio)/12 + interés (asiento a 261005, no se pagan en el neto).
    HE, RNO y dominicales cotizan a salud y pensión. Cuentas: salud (237005), pensión (238030), ARL, caja, SENA, ICBF, cesantías (261005).
    Verifique los valores con su contador antes de liquidar.
</p>
<?php endif; ?>

<?php if ($canCreate || $canEdit): ?>
<div id="employeeModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:720px;">
        <div class="modal-header"><h3 id="employeeModalTitle">Empleado</h3><button type="button" class="modal-close" onclick="closeEmployeeModal()">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=save_employee" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="empId" value="0">
            <div class="modal-body"><div class="nm-modal-grid">
                <div class="form-group"><label>Tipo doc.</label><select class="form-control" name="document_type" id="empDocType"><option value="CC">CC</option><option value="CE">CE</option><option value="NIT">NIT</option><option value="PAS">PAS</option></select></div>
                <div class="form-group"><label>Número *</label><input class="form-control" name="document_number" id="empDoc" required></div>
                <div class="form-group"><label>Nombres *</label><input class="form-control" name="first_name" id="empFirst" required></div>
                <div class="form-group"><label>Apellidos</label><input class="form-control" name="last_name" id="empLast"></div>
                <div class="form-group"><label>Cargo</label><input class="form-control" name="position_title" id="empPos"></div>
                <div class="form-group"><label>Salario * <?php echo nmTip('Salario mensual pactado. Base del valor día (÷30), valor hora (÷ horas mensuales) y aportes.'); ?></label><div class="nm-input prefix"><span class="nm-affix">$</span><input class="form-control" type="text" inputmode="numeric" data-money="1" name="salary" id="empSalary" required></div></div>
                <div class="form-group"><label>Fecha ingreso</label><input class="form-control" type="date" name="hire_date" id="empHire"></div>
                <div class="form-group"><label>Contrato</label><select class="form-control" name="contract_type" id="empContract"><option value="indefinido">Indefinido</option><option value="fijo">Término fijo</option><option value="obra">Obra/labor</option><option value="prestacion">Prestación de servicios</option></select></div>
                <div class="form-group"><label>Horario <?php echo nmTip('Turno semanal del empleado. Se administra en la pestaña Horarios; permite precargar el recargo nocturno en la liquidación.'); ?></label>
                    <select class="form-control" name="schedule_id" id="empSchedule">
                        <option value="0">Sin horario</option>
                        <?php foreach ($schedules as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo nmHours((float)$s['metrics']['weekly_hours']); ?>/sem)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Pago</label><select class="form-control" name="payment_method" id="empPay"><?php echo \SoftNova\Core\payment_method_options('transfer'); ?></select></div>
                <div class="form-group"><label>Cuenta bancaria</label><input class="form-control" name="bank_account" id="empBank"></div>
                <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" id="empEmail"></div>
                <div class="form-group"><label>Teléfono</label><input class="form-control" name="phone" id="empPhone"></div>
                <div class="form-group"><label>Estado</label><select class="form-control" name="status" id="empStatus"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></div>
                <div class="form-group" style="align-self:end;"><label class="nm-check"><input type="checkbox" name="has_transport_aid" id="empTransport" value="1" checked> Auxilio de transporte <?php echo nmTip('Solo se paga si el salario es de hasta 2 SMMLV, aunque la casilla esté marcada.'); ?></label></div>
                <div class="form-group full"><label>Notas</label><textarea class="form-control" name="notes" id="empNotes" rows="2"></textarea></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeEmployeeModal()">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form>
    </div>
</div>

<div id="scheduleModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:760px;">
        <div class="modal-header"><h3 id="scheduleModalTitle">Horario</h3><button type="button" class="modal-close" onclick="closeScheduleModal()">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=save_schedule" data-ajax="true" id="scheduleForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="schId" value="0">
            <div class="modal-body">
                <div class="nm-modal-grid" style="margin-bottom:12px;">
                    <div class="form-group" style="margin:0;"><label>Nombre *</label><input class="form-control" name="name" id="schName" maxlength="80" required placeholder="Ej. Turno mañana"></div>
                    <div class="form-group" style="margin:0;"><label>Color</label><input class="form-control" type="color" name="color" id="schColor" value="#6366f1" style="height:38px;padding:4px;"></div>
                </div>
                <div class="nm-templates">
                    <span style="font-size:12px;color:var(--color-text-secondary);align-self:center;">Plantillas:</span>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="nmTemplate('office')">L-V 8:00-17:00 (40 h)</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="nmTemplate('salon')">L-S 9:00-17:00 (42 h)</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="nmTemplate('late')">L-S 13:00-21:00 (42 h)</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="nmTemplate('half')">Medio tiempo L-S 8:00-12:00</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="nmCopyMonday()">Copiar lunes a días marcados <?php echo nmTip('Copia entrada, salida y descanso del lunes a todos los días marcados como laborables.'); ?></button>
                </div>
                <div class="table-container">
                <table class="nm-week-editor">
                    <thead><tr><th>Día</th><th>Entrada</th><th>Salida <?php echo nmTip('Si la salida es menor que la entrada, el turno termina al día siguiente (turno nocturno).'); ?></th><th>Descanso (min) <?php echo nmTip('Minutos no remunerados de almuerzo o pausa. Se descuentan de las horas del día.'); ?></th><th>Horas</th></tr></thead>
                    <tbody>
                    <?php foreach ($weekdays as $n => $dayName): ?>
                        <tr data-day="<?php echo $n; ?>">
                            <td><label class="nm-check"><input type="checkbox" name="days[<?php echo $n; ?>][on]" value="1" class="sch-on"> <?php echo $dayName; ?></label></td>
                            <td><input class="form-control sch-start" type="time" name="days[<?php echo $n; ?>][start]" value="08:00"></td>
                            <td><input class="form-control sch-end" type="time" name="days[<?php echo $n; ?>][end]" value="17:00"></td>
                            <td><input class="form-control sch-break" type="number" min="0" max="240" step="5" name="days[<?php echo $n; ?>][break]" value="60"></td>
                            <td class="sch-hours" style="font-weight:600;white-space:nowrap;">—</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="nm-summary">
                    <div>Horas/semana<b id="schWeekly">0 h</b></div>
                    <div>Nocturnas/semana <?php echo nmTip('Horas dentro de la ventana nocturna configurada en Parámetros (' . (int)($params['night_start_hour'] ?? 19) . ':00 a ' . (int)($params['night_end_hour'] ?? 6) . ':00).'); ?><b id="schNight">0 h</b></div>
                    <div>Domingo<b id="schSunday">0 h</b></div>
                    <div>HE/semana <?php echo nmTip('Horas por día que superan la jornada máxima diaria de Parámetros (' . nmHours((float)($params['max_daily_hours'] ?? 8)) . '). Se toman del final del turno: nocturnas si caen en la ventana nocturna, dominicales si es domingo.'); ?><b id="schExtra">0 h</b></div>
                    <div>≈ Horas/mes<b id="schMonthly">0 h</b></div>
                </div>
                <ul class="nm-warn" id="schWarnings" style="display:none;margin-top:10px;"></ul>
                <div class="form-group" style="margin:12px 0 0;"><label>Notas</label><input class="form-control" name="notes" id="schNotes" maxlength="255" placeholder="Opcional"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeScheduleModal()">Cancelar</button><button type="submit" class="btn btn-primary">Guardar horario</button></div>
        </form>
    </div>
</div>

<div id="runModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:560px;">
        <div class="modal-header"><h3>Nueva liquidación</h3><button type="button" class="modal-close" onclick="document.getElementById('runModal').style.display='none'">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=create_run" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body"><div class="nm-modal-grid">
                <div class="form-group"><label>Año</label><input class="form-control" type="number" name="period_year" value="<?php echo date('Y'); ?>" required></div>
                <div class="form-group"><label>Mes</label><select class="form-control" name="period_month"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?php echo $i; ?>" <?php echo $i === (int)date('n') ? 'selected' : ''; ?>><?php echo $i; ?></option><?php endfor; ?></select></div>
                <div class="form-group"><label>Fecha de pago</label><input class="form-control" type="date" name="pay_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="form-group"><label>Días trabajados <?php echo nmTip('Días del periodo (base 30). Las incapacidades se descuentan después en el detalle.'); ?></label><input class="form-control" type="number" min="1" max="31" name="days_worked" value="30"></div>
                <div class="form-group full"><label>Medio de pago</label><select class="form-control" name="payment_method"><?php echo \SoftNova\Core\payment_method_options('transfer'); ?></select></div>
                <div class="form-group full" style="margin:0;"><label class="nm-check"><input type="checkbox" name="include_prima" value="1" <?php echo in_array((int)date('n'), [6, 12], true) ? 'checked' : ''; ?>> Incluir prima de servicios <?php echo nmTip('Paga la prima del semestre: (salario + auxilio de transporte si aplica) × % de Parámetros → Provisiones × 6 meses (8,33% = medio mes). Se paga normalmente en junio y diciembre.'); ?></label></div>
                <div class="form-group full" style="margin:0;"><label class="nm-check"><input type="checkbox" name="include_cesantias" value="1"> Incluir cesantías e intereses <?php echo nmTip('Provisión mensual según Parámetros → Provisiones (cesantías % de salario + auxilio de transporte si aplica, + intereses anuales ÷ 12). Se registra en contabilidad y no suma al neto a pagar.'); ?></label></div>
                <div class="form-group full" style="margin:0;"><label class="nm-check"><input type="checkbox" name="include_vacaciones" value="1"> Incluir provisión de vacaciones <?php echo nmTip('Provisión mensual: salario × % de vacaciones de Parámetros → Provisiones. Se contabiliza (510539 / 261015) y no suma al neto a pagar.'); ?></label></div>
                <div class="form-group full" style="margin:0;"><label class="nm-check"><input type="checkbox" name="include_parafiscales" value="1" checked> Parafiscales (caja, SENA, ICBF) <?php echo nmTip('Calcula los % de Parámetros → Parafiscales. La ARL se calcula siempre.'); ?></label></div>
                <div class="form-group full" style="margin:0;"><label class="nm-check"><input type="checkbox" name="schedule_rno" value="1" <?php echo $hasNightSchedules ? 'checked' : ''; ?>> Precargar recargo nocturno según horario <?php echo nmTip('Para empleados con horario asignado, llena las horas RNO del periodo (horas nocturnas semanales × días ÷ 7). Puede ajustarlas luego en el detalle.'); ?></label></div>
                <div class="form-group full" style="margin:0;"><label class="nm-check"><input type="checkbox" name="schedule_ot" value="1" <?php echo $hasOvertimeSchedules ? 'checked' : ''; ?>> Precargar horas extra según horario <?php echo nmTip('Para empleados con horario asignado, las horas de cada día que superan la jornada máxima diaria (Parámetros) se cargan como HED/HEN, o HEDD/HEND si es domingo (semanales × días ÷ 7). Las horas nocturnas que ya son extra no se duplican como RNO. Puede ajustarlas luego en el detalle.'); ?></label></div>
                <div class="form-group full"><label>Notas</label><input class="form-control" name="notes"></div>
                <p class="full" style="font-size:12px;color:var(--color-text-secondary);margin:0;">Tras crear el borrador, capture horas extras en el detalle (tabla masiva o botón HE por empleado).</p>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('runModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-primary">Calcular</button></div>
        </form>
    </div>
</div>

<div id="incapacityModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header"><h3>Incapacidad</h3><button type="button" class="modal-close" onclick="document.getElementById('incapacityModal').style.display='none'">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=save_incapacity" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="item_id" id="incItemId">
            <input type="hidden" name="run_id" id="incRunId">
            <div class="modal-body">
                <div class="form-group"><label>Días de incapacidad</label><input class="form-control" type="number" min="0" max="31" name="incapacity_days" id="incDays"></div>
                <div class="form-group"><label>Tipo <?php echo nmTip('Enfermedad general: días 1-2 al 100% (empleador), desde el día 3 al % de Parámetros. Laboral (ARL): 100%.'); ?></label>
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
<div id="overtimeModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:560px;">
        <div class="modal-header"><h3>Horas extras y recargos</h3><button type="button" class="modal-close" onclick="document.getElementById('overtimeModal').style.display='none'">&times;</button></div>
        <form method="POST" action="<?php echo $base; ?>?action=save_overtime" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="item_id" id="otItemId">
            <input type="hidden" name="run_id" id="otRunId">
            <div class="modal-body">
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 12px;">
                    Horas del periodo. Valor = (salario ÷ horas mensuales) × factor de recargo (Parámetros).
                </p>
                <div class="nm-modal-grid">
                    <div class="form-group"><label>HE diurnas (HED) <?php echo nmTip('Horas extra entre ' . (int)($params['night_end_hour'] ?? 6) . ':00 y ' . (int)($params['night_start_hour'] ?? 19) . ':00.'); ?></label><input class="form-control" type="number" min="0" step="0.25" name="overtime_day_hours" id="otDayHours" value="0"></div>
                    <div class="form-group"><label>HE nocturnas (HEN) <?php echo nmTip('Horas extra en horario nocturno.'); ?></label><input class="form-control" type="number" min="0" step="0.25" name="overtime_night_hours" id="otNightHours" value="0"></div>
                    <div class="form-group"><label>Recargo noct. ord. (RNO) <?php echo nmTip('Horas de la jornada normal en horario nocturno. Solo se paga el % de recargo.'); ?></label><input class="form-control" type="number" min="0" step="0.25" name="night_surcharge_hours" id="otRnoHours" value="0"></div>
                    <div class="form-group"><label>Dominical / festivo ord. <?php echo nmTip('Horas ordinarias trabajadas en domingo o festivo.'); ?></label><input class="form-control" type="number" min="0" step="0.25" name="dominical_hours" id="otDomHours" value="0"></div>
                    <div class="form-group"><label>HE dom. diurna (HEDD)</label><input class="form-control" type="number" min="0" step="0.25" name="overtime_dom_day_hours" id="otHeddHours" value="0"></div>
                    <div class="form-group"><label>HE dom. nocturna (HEND)</label><input class="form-control" type="number" min="0" step="0.25" name="overtime_dom_night_hours" id="otHendHours" value="0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('overtimeModal').style.display='none'">Cerrar</button>
                <button type="submit" class="btn btn-primary">Recalcular</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
window.NM = <?php echo json_encode([
    'params' => array_map('floatval', array_intersect_key($params, \SoftNova\Services\PayrollService::PARAM_DEFAULTS)),
    'suggested' => $suggested ?? new \stdClass(),
    'weekdays' => $weekdays,
    'schedules' => array_map(static fn ($s) => [
        'id' => (int)$s['id'], 'name' => $s['name'], 'color' => $s['color'], 'notes' => (string)($s['notes'] ?? ''), 'days' => $s['days'],
    ], $schedules),
    'symbol' => $currency['symbol'] ?? '$',
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

(function () {
    var pop = null;
    function show(el) {
        var text = el.getAttribute('data-tip');
        if (!text) return;
        if (!pop) { pop = document.createElement('div'); pop.className = 'nm-tip-pop'; document.body.appendChild(pop); }
        pop.textContent = text;
        pop.style.display = 'block';
        var r = el.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
        var left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 8));
        var top = r.top - h - 8;
        if (top < 8) top = r.bottom + 8;
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
    }
    function hide() { if (pop) pop.style.display = 'none'; }
    function tipOf(t) { return t && t.closest ? t.closest('[data-tip]') : null; }
    document.addEventListener('mouseover', function (e) { var el = tipOf(e.target); if (el) show(el); });
    document.addEventListener('mouseout', function (e) { var el = tipOf(e.target); if (el && !el.contains(e.relatedTarget)) hide(); });
    document.addEventListener('focusin', function (e) { var el = tipOf(e.target); if (el) show(el); });
    document.addEventListener('focusout', hide);
    document.addEventListener('click', function (e) {
        var el = tipOf(e.target);
        if (el) { e.preventDefault(); e.stopPropagation(); show(el); } else { hide(); }
    }, true);
    window.addEventListener('scroll', hide, true);
})();

function nmFmtMoney(v) {
    return NM.symbol + ' ' + Math.round(v || 0).toLocaleString('es-CO');
}
function nmFmtH(v) {
    var r = Math.round((v || 0) * 100) / 100;
    return String(r).replace('.', ',') + ' h';
}

/* ── Parámetros: vistas previas y sugerencia ── */
function nmInputNum(el) {
    if (!el) return 0;
    if (el.getAttribute('data-money') === '1' && window.SeriMoney) return SeriMoney.parse(el.value) || 0;
    return parseFloat(el.value) || 0;
}
function nmParamVal(key) {
    var el = document.getElementById('p_' + key);
    return el ? nmInputNum(el) : (NM.params[key] || 0);
}
function nmFmtPct(v) {
    return String(Math.round((v || 0) * 1000) / 1000).replace('.', ',') + ' %';
}
function nmRenderPreviews() {
    var box = function (k) { return document.querySelector('[data-preview="' + k + '"]'); };
    var smmlv = nmParamVal('smmlv'), hours = Math.max(1, nmParamVal('payroll_monthly_hours'));
    var hourly = smmlv / hours;
    var b;
    if ((b = box('base'))) b.innerHTML = '<span class="nm-pill">Tope auxilio: salarios ≤ <b>' + nmFmtMoney(smmlv * 2) + '</b></span>'
        + '<span class="nm-pill">Salario + auxilio mínimo: <b>' + nmFmtMoney(smmlv + nmParamVal('transport_aid')) + '</b></span>';
    if ((b = box('hour'))) b.innerHTML = '<span class="nm-pill">Valor hora con SMMLV: <b>' + nmFmtMoney(hourly) + '</b></span>'
        + '<span class="nm-pill">Nocturno: <b>' + nmParamVal('night_start_hour') + ':00 – ' + nmParamVal('night_end_hour') + ':00</b></span>'
        + '<span class="nm-pill">HE desde: <b>' + nmFmtH(nmParamVal('max_daily_hours')) + '/día</b></span>';
    if ((b = box('emp'))) b.innerHTML = '<span class="nm-pill">Descuento total: <b>' + nmFmtPct(nmParamVal('health_employee_rate') + nmParamVal('pension_employee_rate')) + '</b></span>'
        + '<span class="nm-pill">Sobre SMMLV: <b>' + nmFmtMoney(smmlv * (nmParamVal('health_employee_rate') + nmParamVal('pension_employee_rate')) / 100) + '</b></span>';
    var er = nmParamVal('health_employer_rate') + nmParamVal('pension_employer_rate') + nmParamVal('arl_employer_rate');
    var para = nmParamVal('caja_employer_rate') + nmParamVal('sena_employer_rate') + nmParamVal('icbf_employer_rate');
    if ((b = box('er'))) b.innerHTML = '<span class="nm-pill">Seguridad social empresa: <b>' + nmFmtPct(er) + '</b></span>'
        + '<span class="nm-pill">Sobre SMMLV: <b>' + nmFmtMoney(smmlv * er / 100) + '</b></span>';
    if ((b = box('para'))) b.innerHTML = '<span class="nm-pill">Parafiscales: <b>' + nmFmtPct(para) + '</b></span>'
        + '<span class="nm-pill">Costo empresa total: <b>' + nmFmtPct(er + para) + '</b></span>';
    if ((b = box('prov'))) {
        var auxToggle = document.getElementById('p_prestaciones_include_transport');
        var auxOn = auxToggle ? auxToggle.checked : !!Number(NM.params.prestaciones_include_transport || 0);
        var prestBase = smmlv + (auxOn ? nmParamVal('transport_aid') : 0);
        var ces = prestBase * nmParamVal('cesantias_rate') / 100;
        var cesInt = ces * nmParamVal('cesantias_interest_rate') / 100 / 12;
        var primaM = prestBase * nmParamVal('prima_rate') / 100;
        var vac = smmlv * nmParamVal('vacation_rate') / 100;
        b.innerHTML = '<span style="align-self:center;">Por mes con SMMLV' + (auxOn ? ' + auxilio' : '') + ':</span>'
            + '<span class="nm-pill">Cesantías <b>' + nmFmtMoney(ces) + '</b></span>'
            + '<span class="nm-pill">Intereses <b>' + nmFmtMoney(cesInt) + '</b></span>'
            + '<span class="nm-pill">Prima <b>' + nmFmtMoney(primaM) + '</b> (semestre ' + nmFmtMoney(primaM * 6) + ')</span>'
            + '<span class="nm-pill">Vacaciones <b>' + nmFmtMoney(vac) + '</b></span>'
            + '<span class="nm-pill">Carga prestacional: <b>' + nmFmtPct(smmlv > 0 ? (ces + cesInt + primaM + vac) / smmlv * 100 : 0) + '</b></span>';
    }
    if ((b = box('com'))) {
        var comTotal = 0, comCount = 0;
        document.querySelectorAll('input[data-commission]').forEach(function (el) {
            var v = nmInputNum(el);
            if (v > 0) { comTotal += v; comCount++; }
        });
        b.innerHTML = '<span class="nm-pill">Empleados con comisión: <b>' + comCount + '</b></span>'
            + '<span class="nm-pill">Total por quincena: <b>' + nmFmtMoney(comTotal) + '</b></span>'
            + '<span class="nm-pill">Total al mes (2 quincenas): <b>' + nmFmtMoney(comTotal * 2) + '</b></span>';
    }
    if ((b = box('ot'))) {
        var rows = [['HED', 'overtime_day_rate', 1], ['HEN', 'overtime_night_rate', 1], ['RNO', 'night_surcharge_rate', 0], ['Dom.', 'dominical_rate', 1], ['HEDD', 'overtime_dom_day_rate', 1], ['HEND', 'overtime_dom_night_rate', 1]];
        b.innerHTML = '<span style="align-self:center;">Valor de 1 hora con SMMLV (' + nmFmtMoney(hourly) + '):</span>' + rows.map(function (r) {
            return '<span class="nm-pill">' + r[0] + ' <b>' + nmFmtMoney(hourly * (r[2] + nmParamVal(r[1]) / 100)) + '</b></span>';
        }).join('');
    }
}
function nmSuggestParams() {
    var changed = 0;
    Object.keys(NM.suggested || {}).forEach(function (k) {
        var el = document.getElementById('p_' + k);
        if (!el) return;
        var num = parseFloat(NM.suggested[k]);
        var isMoney = el.getAttribute('data-money') === '1' && window.SeriMoney;
        el.classList.remove('nm-changed');
        if (nmInputNum(el) !== num) {
            el.value = isMoney ? SeriMoney.format(num) : String(num);
            el.classList.add('nm-changed');
            changed++;
        }
    });
    nmRenderPreviews();
    var note = document.getElementById('paramsNote');
    if (note) note.innerHTML = changed ? '<strong>' + changed + ' valor(es) actualizados</strong> (resaltados). Revise y pulse Guardar.' : 'Ya tiene los valores legales 2026.';
}
document.addEventListener('input', function (e) {
    if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-param')) { e.target.classList.remove('nm-changed'); nmRenderPreviews(); }
    else if (e.target && e.target.hasAttribute && (e.target.hasAttribute('data-commission') || e.target.hasAttribute('data-toggle-param'))) { nmRenderPreviews(); }
});
nmRenderPreviews();

/* ── Exportar CSV/PDF sin abrir pestañas en blanco ── */
(function () {
    function notify(msg, type) {
        if (typeof showAlert === 'function') { showAlert(msg, type); } else { alert(msg); }
    }
    function fileNameFrom(res, fallback) {
        var cd = res.headers.get('Content-Disposition') || '';
        var m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
        return m ? decodeURIComponent(m[1]) : fallback;
    }
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-export-download]');
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey || !window.fetch) return;
        e.preventDefault();
        if (link.dataset.busy === '1') return;
        var original = link.textContent;
        link.dataset.busy = '1';
        link.classList.add('disabled');
        link.textContent = 'Generando…';
        fetch(link.href, { credentials: 'same-origin' })
            .then(function (res) {
                var type = (res.headers.get('Content-Type') || '').toLowerCase();
                var isFile = type.indexOf('application/pdf') !== -1 || type.indexOf('text/csv') !== -1;
                if (!res.ok || !isFile) {
                    return res.text().then(function (txt) {
                        throw new Error(type.indexOf('text/plain') !== -1 && txt.trim() !== ''
                            ? txt.trim().slice(0, 300)
                            : 'No se pudo generar el archivo (sesión expirada o sin permiso). Recargue la página e intente de nuevo.');
                    });
                }
                var name = fileNameFrom(res, type.indexOf('pdf') !== -1 ? 'reporte.pdf' : 'export.csv');
                return res.blob().then(function (blob) {
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = name;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
                    notify('Descarga lista: ' + name, 'success');
                });
            })
            .catch(function (err) { notify(err && err.message ? err.message : 'Error de red al exportar', 'error'); })
            .finally(function () {
                link.dataset.busy = '';
                link.classList.remove('disabled');
                link.textContent = original;
            });
    });
})();

/* ── Horarios ── */
function nmMin(t) { var p = String(t || '').split(':'); return p.length === 2 ? (+p[0]) * 60 + (+p[1]) : null; }
function nmScheduleMetrics() {
    var ns = (NM.params.night_start_hour != null ? NM.params.night_start_hour : 19) * 60;
    var ne = (NM.params.night_end_hour != null ? NM.params.night_end_hour : 6) * 60;
    var wins = ns > ne ? [[0, ne], [ns, 1440 + ne], [1440 + ns, 2880]] : [[ns, ne], [1440 + ns, 1440 + ne]];
    var maxDailyMin = Math.round(Math.max(1, NM.params.max_daily_hours || 8) * 60);
    var overlapNight = function (a, b) { var m = 0; wins.forEach(function (w) { m += Math.max(0, Math.min(b, w[1]) - Math.max(a, w[0])); }); return m; };
    var weekly = 0, night = 0, sunday = 0, extra = 0, extraNight = 0, worked = 0, warnings = [];
    document.querySelectorAll('#scheduleForm tr[data-day]').forEach(function (tr) {
        var n = +tr.getAttribute('data-day');
        var on = tr.querySelector('.sch-on').checked;
        tr.classList.toggle('off', !on);
        var cell = tr.querySelector('.sch-hours');
        if (!on) { cell.textContent = '—'; return; }
        worked++;
        var s = nmMin(tr.querySelector('.sch-start').value), e = nmMin(tr.querySelector('.sch-end').value);
        var br = Math.max(0, Math.min(240, parseInt(tr.querySelector('.sch-break').value, 10) || 0));
        if (s === null || e === null || s === e) { cell.textContent = '!'; return; }
        var end = e > s ? e : e + 1440, span = end - s, nightMin = 0;
        wins.forEach(function (w) { nightMin += Math.max(0, Math.min(end, w[1]) - Math.max(s, w[0])); });
        var dayMin = span - nightMin;
        if (br > dayMin) nightMin = Math.max(0, nightMin - (br - dayMin));
        var net = Math.max(0, span - br);
        var exMin = Math.max(0, net - maxDailyMin);
        var exNight = exMin > 0 ? Math.min(exMin, nightMin, overlapNight(end - exMin, end)) : 0;
        cell.textContent = nmFmtH(net / 60) + (nightMin > 0 ? ' (' + nmFmtH(nightMin / 60) + ' noct.)' : '')
            + (exMin > 0 ? ' +' + nmFmtH(exMin / 60) + ' HE' : '');
        weekly += net / 60; night += nightMin / 60; extra += exMin / 60; extraNight += exNight / 60;
        if (n === 7) sunday += net / 60;
        if (exMin > 0) warnings.push(NM.weekdays[n] + ': ' + nmFmtH(exMin / 60) + ' sobre la jornada diaria (' + nmFmtH(maxDailyMin / 60) + ') → hora extra' + (exNight > 0 ? ' (' + nmFmtH(exNight / 60) + ' nocturna)' : '') + (n === 7 ? ' dominical' : ''));
        if (net > 600) warnings.push(NM.weekdays[n] + ': más de 10 h efectivas en el día');
    });
    var max = NM.params.max_weekly_hours || 42;
    if (weekly - extra > max + 0.001) warnings.push('Horas ordinarias sobre la jornada máxima (' + max + ' h/semana): el exceso de ' + nmFmtH(weekly - extra - max) + ' debe liquidarse como horas extra');
    if (worked === 7) warnings.push('Sin día de descanso semanal');
    document.getElementById('schWeekly').textContent = nmFmtH(weekly);
    document.getElementById('schNight').textContent = nmFmtH(night);
    document.getElementById('schSunday').textContent = nmFmtH(sunday);
    document.getElementById('schExtra').textContent = nmFmtH(extra) + (extraNight > 0 ? ' (' + nmFmtH(extraNight) + ' noct.)' : '');
    document.getElementById('schMonthly').textContent = nmFmtH(weekly * 30 / 7);
    var ul = document.getElementById('schWarnings');
    ul.innerHTML = warnings.map(function (w) { var li = document.createElement('li'); li.textContent = w; return li.outerHTML; }).join('');
    ul.style.display = warnings.length ? '' : 'none';
}
function nmSetDays(days) {
    document.querySelectorAll('#scheduleForm tr[data-day]').forEach(function (tr) {
        var d = days[tr.getAttribute('data-day')] || { on: false, start: '08:00', end: '17:00', 'break': 60 };
        tr.querySelector('.sch-on').checked = !!d.on;
        tr.querySelector('.sch-start').value = d.start || '08:00';
        tr.querySelector('.sch-end').value = d.end || '17:00';
        tr.querySelector('.sch-break').value = d['break'] != null ? d['break'] : 60;
    });
    nmScheduleMetrics();
}
function nmTemplate(kind) {
    var cfg = {
        office: { days: [1, 2, 3, 4, 5], start: '08:00', end: '17:00', br: 60 },
        salon: { days: [1, 2, 3, 4, 5, 6], start: '09:00', end: '17:00', br: 60 },
        late: { days: [1, 2, 3, 4, 5, 6], start: '13:00', end: '21:00', br: 60 },
        half: { days: [1, 2, 3, 4, 5, 6], start: '08:00', end: '12:00', br: 0 }
    }[kind];
    if (!cfg) return;
    var days = {};
    for (var n = 1; n <= 7; n++) days[n] = { on: cfg.days.indexOf(n) !== -1, start: cfg.start, end: cfg.end, 'break': cfg.br };
    nmSetDays(days);
}
function nmCopyMonday() {
    var rows = document.querySelectorAll('#scheduleForm tr[data-day]');
    var mon = rows[0];
    rows.forEach(function (tr, i) {
        if (i === 0 || !tr.querySelector('.sch-on').checked) return;
        tr.querySelector('.sch-start').value = mon.querySelector('.sch-start').value;
        tr.querySelector('.sch-end').value = mon.querySelector('.sch-end').value;
        tr.querySelector('.sch-break').value = mon.querySelector('.sch-break').value;
    });
    nmScheduleMetrics();
}
function openScheduleModal(id) {
    var s = id ? NM.schedules[id] : null;
    document.getElementById('scheduleModalTitle').textContent = s ? 'Editar horario' : 'Nuevo horario';
    document.getElementById('schId').value = s ? s.id : 0;
    document.getElementById('schName').value = s ? s.name : '';
    document.getElementById('schColor').value = s ? s.color : '#6366f1';
    document.getElementById('schNotes').value = s ? s.notes : '';
    if (s) nmSetDays(s.days); else nmTemplate('salon');
    document.getElementById('scheduleModal').style.display = 'flex';
}
function closeScheduleModal() { document.getElementById('scheduleModal').style.display = 'none'; }
(function () {
    var form = document.getElementById('scheduleForm');
    if (!form) return;
    form.addEventListener('input', nmScheduleMetrics);
    form.addEventListener('change', nmScheduleMetrics);
})();

/* ── Asignación masiva ── */
(function () {
    var form = document.getElementById('assignForm');
    if (!form) return;
    var all = document.getElementById('assignAll'), search = document.getElementById('assignSearch');
    var btn = document.getElementById('assignBtn'), count = document.getElementById('assignCount');
    function visibleBoxes() {
        return Array.prototype.filter.call(form.querySelectorAll('.assign-cb'), function (cb) { return cb.closest('tr').style.display !== 'none'; });
    }
    function refresh() {
        var n = form.querySelectorAll('.assign-cb:checked').length;
        if (count) count.textContent = n;
        if (btn) btn.disabled = n === 0;
        var vis = visibleBoxes();
        all.checked = vis.length > 0 && vis.every(function (cb) { return cb.checked; });
    }
    all.addEventListener('change', function () { visibleBoxes().forEach(function (cb) { cb.checked = all.checked; }); refresh(); });
    form.addEventListener('change', function (e) { if (e.target.classList.contains('assign-cb')) refresh(); });
    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        form.querySelectorAll('tbody tr').forEach(function (tr) {
            tr.style.display = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1 ? '' : 'none';
        });
        refresh();
    });
    refresh();
})();

/* ── Empleados / liquidación ── */
function openEmployeeModal() {
    document.getElementById('employeeModalTitle').textContent = 'Nuevo empleado';
    document.getElementById('empId').value = '0';
    ['empDoc','empFirst','empLast','empPos','empSalary','empHire','empBank','empEmail','empPhone','empNotes'].forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('empTransport').checked = true;
    document.getElementById('empStatus').value = 'active';
    document.getElementById('empSchedule').value = '0';
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
    document.getElementById('empSalary').value = window.SeriMoney ? SeriMoney.format(parseFloat(e.salary) || 0) : (e.salary || 0);
    document.getElementById('empHire').value = e.hire_date || '';
    document.getElementById('empContract').value = e.contract_type || 'indefinido';
    document.getElementById('empPay').value = e.payment_method || 'transfer';
    document.getElementById('empBank').value = e.bank_account || '';
    document.getElementById('empEmail').value = e.email || '';
    document.getElementById('empPhone').value = e.phone || '';
    document.getElementById('empStatus').value = e.status || 'active';
    document.getElementById('empTransport').checked = String(e.has_transport_aid) === '1';
    document.getElementById('empNotes').value = e.notes || '';
    var sch = document.getElementById('empSchedule');
    sch.value = e.schedule_id ? String(e.schedule_id) : '0';
    if (sch.value === '') sch.value = '0';
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
function editOvertime(itemId, runId, data) {
    document.getElementById('otItemId').value = itemId;
    document.getElementById('otRunId').value = runId;
    document.getElementById('otDayHours').value = data.day || 0;
    document.getElementById('otNightHours').value = data.night || 0;
    document.getElementById('otRnoHours').value = data.rno || 0;
    document.getElementById('otDomHours').value = data.dom || 0;
    document.getElementById('otHeddHours').value = data.hedd || 0;
    document.getElementById('otHendHours').value = data.hend || 0;
    document.getElementById('overtimeModal').style.display = 'flex';
}
</script>
