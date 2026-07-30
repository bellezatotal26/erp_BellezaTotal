<?php
/**
 * Encabezado ordenable.
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
$href = $baseUrl . '?' . http_build_query($query);
$arrow = '';
if ($currentSort === $column) {
    $arrow = $currentDir === 'asc' ? ' ↑' : ' ↓';
}
?>
<a href="<?php echo htmlspecialchars($href); ?>" style="color:inherit;text-decoration:none;white-space:nowrap;" title="Ordenar por <?php echo htmlspecialchars($label); ?>">
    <?php echo htmlspecialchars($label); ?><?php echo $arrow; ?>
</a>
