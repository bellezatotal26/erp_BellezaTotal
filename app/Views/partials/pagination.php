<?php
$pagination = $pagination ?? null;
if (!$pagination || ($pagination['totalPages'] ?? 1) <= 1) {
    return;
}
$page = (int)$pagination['page'];
$totalPages = (int)$pagination['totalPages'];
$total = (int)$pagination['total'];
$baseUrl = $paginationBaseUrl ?? '';
$query = $paginationQuery ?? [];
unset($query['page']);

function buildPageUrl(string $baseUrl, array $query, int $page): string
{
    $query['page'] = $page;
    $qs = http_build_query($query);
    return $baseUrl . ($qs ? ('?' . $qs) : '');
}
?>
<div class="pagination-bar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px;padding-top:12px;border-top:1px solid var(--color-border);">
    <span style="font-size:13px;color:var(--color-text-secondary);">
        Total: <?php echo $total; ?> | Pagina <?php echo $page; ?> de <?php echo $totalPages; ?>
    </span>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars(buildPageUrl($baseUrl, $query, $page - 1)); ?>" title="Anterior">Anterior</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
            <a class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"
               href="<?php echo htmlspecialchars(buildPageUrl($baseUrl, $query, $i)); ?>"
               title="Pagina <?php echo $i; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars(buildPageUrl($baseUrl, $query, $page + 1)); ?>" title="Siguiente">Siguiente</a>
        <?php endif; ?>
    </div>
</div>
