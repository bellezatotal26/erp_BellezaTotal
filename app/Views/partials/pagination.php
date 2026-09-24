<?php
$pagination = $pagination ?? null;
$perPageOptions = $paginationPerPageOptions ?? $perPageOptions ?? [];
if (!is_array($perPageOptions)) {
    $perPageOptions = [];
}
$perPageOptions = array_values(array_unique(array_map('intval', $perPageOptions)));
$showPerPage = $perPageOptions !== [];
if (!$pagination || (($pagination['totalPages'] ?? 1) <= 1 && !$showPerPage)) {
    return;
}
if (($pagination['total'] ?? 0) <= 0 && !$showPerPage) {
    return;
}

$page = (int)$pagination['page'];
$totalPages = max(1, (int)$pagination['totalPages']);
$total = (int)$pagination['total'];
$currentPerPage = (int)($pagination['perPage'] ?? ($perPageOptions[0] ?? 20));
$baseUrl = $paginationBaseUrl ?? '';
$query = $paginationQuery ?? [];
if (!is_array($query)) {
    $query = [];
}
unset($query['page']);

if (!function_exists('buildPageUrl')) {
    function buildPageUrl(string $baseUrl, array $query, int $page): string
    {
        $query['page'] = $page;
        $qs = http_build_query($query);
        return $baseUrl . ($qs ? ('?' . $qs) : '');
    }
}

$perPageQuery = $query;
unset($perPageQuery['page'], $perPageQuery['per_page']);
?>
<div class="pagination-bar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:none;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:13px;color:var(--color-text-secondary);">
            Total: <?php echo $total; ?> | Pagina <?php echo $page; ?> de <?php echo $totalPages; ?>
            <?php if ($currentPerPage > 0): ?>
                <span style="opacity:.75;">(<?php echo (int)$currentPerPage; ?> / pág.)</span>
            <?php endif; ?>
        </span>
        <?php if ($showPerPage): ?>
            <form method="GET" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>"
                  class="pagination-per-page-form"
                  style="display:inline-flex;align-items:center;gap:6px;margin:0;font-size:13px;color:var(--color-text-secondary);font-weight:600;">
                <?php foreach ($perPageQuery as $qk => $qv): ?>
                    <?php if ($qv === null || $qv === '') continue; ?>
                    <input type="hidden" name="<?php echo htmlspecialchars((string)$qk, ENT_QUOTES, 'UTF-8'); ?>"
                           value="<?php echo htmlspecialchars(is_scalar($qv) ? (string)$qv : '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="page" value="1">
                <label class="pagination-per-page" style="display:inline-flex;align-items:center;gap:6px;margin:0;font-weight:600;">
                    <span>Ver</span>
                    <select name="per_page" class="form-control" style="width:auto;min-width:90px;padding:4px 8px;font-size:13px;"
                            aria-label="Elementos por página"
                            onchange="this.form.submit()">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <option value="<?php echo (int)$opt; ?>" <?php echo (int)$opt === $currentPerPage ? 'selected' : ''; ?>>
                                <?php echo (int)$opt; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span>por página</span>
                </label>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
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
    <?php endif; ?>
</div>
