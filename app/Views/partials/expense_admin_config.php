<?php
/**
 * Panel administración de gastos (Configuración).
 * @var list<array<string,mixed>> $expenseCategories
 * @var list<array<string,mixed>> $expenseSubgroups
 * @var string $configBase
 */
$expenseCategories = $expenseCategories ?? [];
$expenseSubgroups = $expenseSubgroups ?? [];
$ECS = \SoftNova\Services\ExpenseCategoryService::class;
$SG_PREFIX = \SoftNova\Services\ExpenseCategoryService::SUBGROUP_KIND_PREFIX;

$expOp = array_values(array_filter($expenseCategories, static fn($c) => ($c['status'] ?? '') === 'active' && ($c['kind'] ?? '') === 'operational'));
$expAdm = array_values(array_filter($expenseCategories, static fn($c) => ($c['status'] ?? '') === 'active' && ($c['kind'] ?? '') === 'administrative'));
$expFin = array_values(array_filter($expenseCategories, static fn($c) => ($c['status'] ?? '') === 'active' && ($c['kind'] ?? '') === 'financial'));
$expMgmt = array_values(array_filter($expenseCategories, static fn($c) => ($c['status'] ?? '') === 'active' && ($c['kind'] ?? '') === 'management'));
$sortByName = static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
usort($expOp, $sortByName);
usort($expAdm, $sortByName);
usort($expFin, $sortByName);
usort($expMgmt, $sortByName);

$builtinContable = [
    ['administrative', $ECS::kindGroupLabel('administrative'), $expAdm, '519595'],
    ['financial', $ECS::kindGroupLabel('financial'), $expFin, '530505'],
    ['management', $ECS::kindGroupLabel('management'), $expMgmt, '519595'],
];

$customContable = [];
foreach ($expenseSubgroups as $sg) {
    if (($sg['status'] ?? '') !== 'active') {
        continue;
    }
    $kind = $ECS::subgroupKind((string)$sg['code']);
    $rows = array_values(array_filter(
        $expenseCategories,
        static fn($c) => ($c['status'] ?? '') === 'active' && ($c['kind'] ?? '') === $kind
    ));
    usort($rows, $sortByName);
    $customContable[] = [
        'kind' => $kind,
        'subgroup_id' => (int)$sg['id'],
        'title' => 'Gastos contables — ' . (string)$sg['name'],
        'rows' => $rows,
        'default_account' => (string)($sg['account_code'] ?? '519595'),
        'subgroup_name' => (string)$sg['name'],
    ];
}

$moveTargets = [
    'operational' => $ECS::kindGroupLabel('operational'),
    'administrative' => $ECS::kindLabel('administrative'),
    'financial' => $ECS::kindLabel('financial'),
    'management' => $ECS::kindLabel('management'),
];
foreach ($customContable as $cc) {
    $moveTargets[$cc['kind']] = (string)$cc['subgroup_name'];
}

$contablePanels = [];
foreach ($builtinContable as [$kindKey, $kindTitle, $rows, $defaultAcct]) {
    $contablePanels[] = [
        'kind' => $kindKey,
        'title' => $kindTitle,
        'rows' => $rows,
        'default_account' => $defaultAcct,
        'subgroup_id' => 0,
        'subgroup_name' => '',
    ];
}
foreach ($customContable as $cc) {
    $contablePanels[] = $cc;
}
?>

<div class="expense-admin-wrap">
    <div class="card neumorphic expense-admin-card">
        <div class="card-header expense-admin-card__header">
            <div>
                <h3>Administración de gastos</h3>
                <p class="expense-admin-intro">
                    <strong>Operativos</strong> descuentan caja · <strong>Contables</strong> no descuentan caja y se contabilizan por sub-grupo.
                    Cree categorías dentro de cada grupo o defina un <strong>nuevo sub-grupo contable</strong> si necesita segmentar más.
                </p>
            </div>
            <div class="expense-admin-toolbar">
                <button type="button" class="btn btn-primary" onclick="expCatOpenAddModal()">+ Nueva categoría</button>
                <button type="button" class="btn btn-secondary" onclick="expSubgroupOpenModal()">+ Nuevo sub-grupo contable</button>
            </div>
        </div>
        <div class="card-body">

            <section class="expense-admin-section expense-admin-section--operational">
                <div class="expense-admin-section__title">
                    <h4><?php echo htmlspecialchars($ECS::kindGroupLabel('operational')); ?></h4>
                    <span class="badge badge-success"><?php echo count($expOp); ?></span>
                    <small>Descuentan caja si paga en efectivo</small>
                </div>
                <?php if ($expOp === []): ?>
                    <p class="muted">Sin categorías operativas.</p>
                <?php else: ?>
                <div class="table-container expense-admin-table-wrap">
                    <table class="expense-admin-table">
                        <thead><tr><th>Categoría</th><th style="width:110px;">Cuenta PUC</th><th style="width:220px;">Mover a</th><th style="width:120px;">Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($expOp as $cat): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$cat['name']); ?></td>
                                <td><code><?php echo htmlspecialchars((string)($cat['account_code'] ?? '')); ?></code></td>
                                <td>
                                    <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategoryMove" class="expense-admin-move-form" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                        <select name="kind" class="form-control form-control-sm" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                            <?php foreach ($moveTargets as $mk => $ml): if ($mk === 'operational') continue; ?>
                                            <option value="<?php echo htmlspecialchars($mk); ?>"><?php echo htmlspecialchars($ml); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="expense-admin-actions">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="expCatEdit(<?php echo (int)$cat['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$cat['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, 'operational', '<?php echo htmlspecialchars((string)($cat['account_code'] ?? '')); ?>')">Editar</button>
                                    <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategoryDelete" data-ajax="true" style="display:inline;">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="¿Eliminar del listado?">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>

            <section class="expense-admin-section expense-admin-section--accounting">
                <div class="expense-admin-section__title">
                    <h4><?php echo htmlspecialchars($ECS::accountingParentLabel()); ?></h4>
                    <span class="badge badge-info"><?php echo count($expAdm) + count($expFin) + count($expMgmt) + array_sum(array_map(static fn($c) => count($c['rows']), $customContable)); ?></span>
                    <small>No descuentan caja · trazabilidad por sub-grupo</small>
                </div>

                <div class="expense-admin-subgroups">
                    <?php foreach ($contablePanels as $panel):
                        $kindKey = (string)$panel['kind'];
                        $kindTitle = (string)$panel['title'];
                        $rows = $panel['rows'];
                        $defaultAcct = (string)$panel['default_account'];
                        $subgroupId = (int)($panel['subgroup_id'] ?? 0);
                        $subgroupName = (string)($panel['subgroup_name'] ?? '');
                        $isCustom = str_starts_with($kindKey, $SG_PREFIX);
                    ?>
                    <div class="expense-admin-panel">
                        <div class="expense-admin-panel__head">
                            <h5><?php echo htmlspecialchars((string)$kindTitle); ?></h5>
                            <span class="badge"><?php echo count($rows); ?></span>
                        </div>
                        <?php if ($rows === []): ?>
                            <p class="muted" style="font-size:12px;margin:0 0 10px;">Sin categorías.</p>
                        <?php else: ?>
                        <ul class="expense-admin-list">
                            <?php foreach ($rows as $cat): ?>
                            <li>
                                <div class="expense-admin-list__main">
                                    <span><?php echo htmlspecialchars((string)$cat['name']); ?></span>
                                    <code class="account"><?php echo htmlspecialchars((string)($cat['account_code'] ?? '')); ?></code>
                                </div>
                                <div class="expense-admin-actions">
                                    <button type="button" class="btn btn-sm btn-secondary" title="Editar"
                                        onclick="expCatEdit(<?php echo (int)$cat['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$cat['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, '<?php echo htmlspecialchars((string)$kindKey); ?>', '<?php echo htmlspecialchars((string)($cat['account_code'] ?? '')); ?>')">✎</button>
                                    <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategoryMove" data-ajax="true" style="display:inline;" title="Mover a operativos">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                        <input type="hidden" name="kind" value="operational">
                                        <button type="submit" class="btn btn-sm btn-secondary">→ Op.</button>
                                    </form>
                                    <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategoryDelete" data-ajax="true" style="display:inline;">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="¿Eliminar?">×</button>
                                    </form>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategorySave" data-ajax="true" class="expense-admin-inline-form">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <input type="hidden" name="kind" value="<?php echo htmlspecialchars((string)$kindKey); ?>">
                            <input type="text" name="name" class="form-control form-control-sm" required placeholder="Nueva categoría…">
                            <input type="text" name="account_code" class="form-control form-control-sm" placeholder="<?php echo htmlspecialchars((string)$defaultAcct); ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Agregar</button>
                        </form>
                        <?php if ($isCustom && $subgroupId > 0): ?>
                        <form method="POST" action="<?php echo $configBase; ?>?action=expenseSubgroupDelete" data-ajax="true" style="margin-top:8px;">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo $subgroupId; ?>">
                            <button type="submit" class="btn btn-sm btn-link text-danger" data-confirm="¿Ocultar el sub-grupo «<?php echo htmlspecialchars($subgroupName); ?>»? Las categorías existentes se conservan.">Eliminar sub-grupo</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<div id="expCatAddModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Nueva categoría de gasto</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('expCatAddModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $configBase; ?>?action=expenseCategorySave" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Grupo / sub-grupo *</label>
                    <select name="kind" class="form-control" required>
                        <option value="operational"><?php echo htmlspecialchars($ECS::kindGroupLabel('operational')); ?></option>
                        <option value="administrative"><?php echo htmlspecialchars($ECS::kindGroupLabel('administrative')); ?></option>
                        <option value="financial"><?php echo htmlspecialchars($ECS::kindGroupLabel('financial')); ?></option>
                        <option value="management"><?php echo htmlspecialchars($ECS::kindGroupLabel('management')); ?></option>
                        <?php foreach ($customContable as $cc): ?>
                        <option value="<?php echo htmlspecialchars($cc['kind']); ?>"><?php echo htmlspecialchars($cc['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nombre *</label>
                    <input type="text" name="name" class="form-control" required placeholder="Ej. Papelería, Comisión bancaria…">
                </div>
                <div class="form-group">
                    <label>Cuenta PUC</label>
                    <input type="text" name="account_code" class="form-control" placeholder="Opcional — usa la del grupo">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('expCatAddModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear categoría</button>
            </div>
        </form>
    </div>
</div>

<div id="expSubgroupModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Nuevo sub-grupo contable</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('expSubgroupModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $configBase; ?>?action=expenseSubgroupSave" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 12px;">
                    Los sub-grupos contables agrupan categorías que <strong>no descuentan caja</strong> (ej. «Gastos de nómina», «Impuestos municipales»).
                </p>
                <div class="form-group">
                    <label>Nombre del sub-grupo *</label>
                    <input type="text" name="name" class="form-control" required placeholder="Ej. Gastos de nómina">
                </div>
                <div class="form-group">
                    <label>Cuenta PUC por defecto</label>
                    <input type="text" name="account_code" class="form-control" placeholder="519595">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('expSubgroupModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear sub-grupo</button>
            </div>
        </form>
    </div>
</div>
