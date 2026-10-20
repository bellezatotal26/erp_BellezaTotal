<?php
/**
 * Encabezado ordenable (A→Z / menor→mayor y Z→A / mayor→menor).
 * Vars: $label, $column, $filters (sort/dir/query), $baseUrl
 */
$label = $label ?? '';
$column = $column ?? '';
$filters = $filters ?? [];
$baseUrl = $baseUrl ?? '';
$currentSort = $filters['sort'] ?? '';
$currentDir = $filters['dir'] ?? 'desc';
$nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
$query = $filters['query'] ?? [];
$query['sort'] = $column;
$query['dir'] = $nextDir;
unset($query['page']);
$href = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
$tip = 'Clic: A→Z / menor→mayor · otro clic: Z→A / mayor→menor';
$arrowHtml = '<span class="sortable-th__arrow" style="opacity:.35;" aria-hidden="true"> ↕</span>';
if ($currentSort === $column) {
    $arrowHtml = '<span class="sortable-th__arrow" aria-hidden="true">'
        . ($currentDir === 'asc' ? ' ↑' : ' ↓')
        . '</span>';
    $tip = $currentDir === 'asc'
        ? 'Orden actual: A→Z / menor→mayor. Clic para invertir.'
        : 'Orden actual: Z→A / mayor→menor. Clic para invertir.';
}
?>
<a href="<?php echo htmlspecialchars($href); ?>" class="sortable-th" style="color:inherit;text-decoration:none;white-space:nowrap;" title="<?php echo htmlspecialchars($tip); ?>">
    <?php echo htmlspecialchars($label); ?><?php echo $arrowHtml; ?>
</a>
