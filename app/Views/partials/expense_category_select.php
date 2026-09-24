<?php
/**
 * Select de categorías de gasto (operativos / contables por sub-grupo).
 * Los contables no descuentan caja y se contabilizan por sub-grupo.
 * @var list<array<string,mixed>> $operationalCats
 * @var list<array<string,mixed>> $financialCats
 * @var list<array<string,mixed>> $administrativeCats
 * @var list<array<string,mixed>> $managementCats
 * @var string $selectedId
 * @var bool $required
 * @var bool $allowCustomExpenseCategory Solo admin: opción "Otro…" por sub-grupo
 */
use SoftNova\Services\ExpenseCategoryService;

$operationalCats = $operationalCats ?? [];
$financialCats = $financialCats ?? [];
$administrativeCats = $administrativeCats ?? [];
$managementCats = $managementCats ?? [];
$selectedId = (string)($selectedId ?? '');
$required = !empty($required);
$defaultOperationalCode = $defaultOperationalCode ?? 'transport';
$customSubgroupSections = $customSubgroupSections ?? [];
$allowCustomExpenseCategory = !empty($allowCustomExpenseCategory);
?>
<select name="category_id" class="form-control" <?php echo $required ? 'required' : ''; ?>
    <?php if ($allowCustomExpenseCategory): ?>data-custom-other="expense_category"<?php endif; ?>>
    <option value="">Seleccionar…</option>
    <optgroup label="Gastos operativos (descuentan caja)">
        <?php foreach ($operationalCats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-kind="operational"
                <?php echo ($selectedId !== '' && (string)$c['id'] === $selectedId) || ($selectedId === '' && ($c['code'] ?? '') === $defaultOperationalCode) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$c['name']); ?>
            </option>
        <?php endforeach; ?>
        <?php if ($allowCustomExpenseCategory): ?>
        <option value="__other_operational__" data-kind="operational">+ Agregar otro gasto operativo…</option>
        <?php endif; ?>
    </optgroup>
    <optgroup label="<?php echo htmlspecialchars(ExpenseCategoryService::kindGroupLabel('administrative')); ?> (no descuentan caja)">
        <?php foreach ($administrativeCats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-kind="administrative" data-accounting="1"
                <?php echo $selectedId !== '' && (string)$c['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$c['name']); ?>
            </option>
        <?php endforeach; ?>
        <?php if ($allowCustomExpenseCategory): ?>
        <option value="__other_administrative__" data-kind="administrative">+ Agregar otro gasto administrativo…</option>
        <?php endif; ?>
    </optgroup>
    <optgroup label="<?php echo htmlspecialchars(ExpenseCategoryService::kindGroupLabel('financial')); ?> (no descuentan caja)">
        <?php foreach ($financialCats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-kind="financial" data-accounting="1"
                <?php echo $selectedId !== '' && (string)$c['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$c['name']); ?>
            </option>
        <?php endforeach; ?>
        <?php if ($allowCustomExpenseCategory): ?>
        <option value="__other_financial__" data-kind="financial">+ Agregar otro gasto financiero…</option>
        <?php endif; ?>
    </optgroup>
    <optgroup label="<?php echo htmlspecialchars(ExpenseCategoryService::kindGroupLabel('management')); ?> (no descuentan caja)">
        <?php foreach ($managementCats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-kind="management" data-accounting="1"
                <?php echo $selectedId !== '' && (string)$c['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$c['name']); ?>
            </option>
        <?php endforeach; ?>
        <?php if ($allowCustomExpenseCategory): ?>
        <option value="__other_management__" data-kind="management" data-accounting="1">+ Agregar otro gasto de gestión…</option>
        <?php endif; ?>
    </optgroup>
    <?php foreach ($customSubgroupSections as $section):
        $sgKind = (string)($section['kind'] ?? '');
        $sgCats = $section['categories'] ?? [];
        $sgTitle = htmlspecialchars((string)($section['subgroup']['name'] ?? 'Contable'));
    ?>
    <optgroup label="Gastos contables — <?php echo $sgTitle; ?> (no descuentan caja)">
        <?php foreach ($sgCats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" data-kind="<?php echo htmlspecialchars($sgKind); ?>" data-accounting="1"
                <?php echo $selectedId !== '' && (string)$c['id'] === $selectedId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$c['name']); ?>
            </option>
        <?php endforeach; ?>
        <?php if ($allowCustomExpenseCategory): ?>
        <option value="__other_<?php echo htmlspecialchars(str_replace(['.', '-'], '_', $sgKind)); ?>__" data-kind="<?php echo htmlspecialchars($sgKind); ?>" data-accounting="1">+ Agregar en este sub-grupo…</option>
        <?php endif; ?>
    </optgroup>
    <?php endforeach; ?>
</select>
