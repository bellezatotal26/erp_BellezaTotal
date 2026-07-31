<?php
$isSuperAdmin = $isSuperAdmin ?? false;

// Obtener conteo de tickets pendientes para notificaciones
$pendingTickets = 0;
$urgentTickets = 0;
if ($isSuperAdmin) {
    try {
        $masterDb = \SoftNova\Core\Database::getInstance();
        $pendingTickets = (int)$masterDb->query("SELECT COUNT(*) FROM tickets WHERE status IN ('open','in_progress')")->fetchColumn();
        $urgentTickets = (int)$masterDb->query("SELECT COUNT(*) FROM tickets WHERE priority='urgent' AND status IN ('open','in_progress')")->fetchColumn();
    } catch (\Exception $e) { /* silencioso */ }
}
?>

<header class="header <?php echo $isSuperAdmin ? 'superadmin-header' : ''; ?>">
    <div class="header-left">
        <h2><?php echo $viewInstance->escape($pageTitle ?? 'Dashboard'); ?></h2>
    </div>
    
    <?php if ($isSuperAdmin): ?>
    <div class="header-search" id="globalSearch">
        <div class="search-input-wrapper">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text"
                   id="globalSearchInput"
                   class="search-input"
                   placeholder="Buscar clientes, tickets, licencias..."
                   autocomplete="off"
                   data-search-url="<?php echo $viewInstance->route('superadmin/search'); ?>">
            <button class="search-clear" id="searchClear" style="display:none;" onclick="clearSearch()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="search-results" id="searchResults" style="display:none;"></div>
    </div>
    <?php endif; ?>
    
    <div class="header-right">
        <?php if ($isSuperAdmin): ?>
        <!-- Notificaciones de Tickets -->
        <a href="<?php echo $viewInstance->route('superadmin/tickets'); ?>" class="header-icon-btn notification-bell" title="Tickets de soporte">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <?php if ($pendingTickets > 0): ?>
                <span class="notification-badge <?php echo $urgentTickets > 0 ? 'badge-urgent' : ''; ?>"><?php echo $pendingTickets; ?></span>
            <?php endif; ?>
            <span class="header-icon-label">Tickets</span>
        </a>
        <?php endif; ?>
        
        <div class="datetime-info">
            <div class="datetime-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="datetime-text">
                <span id="currentDate"></span>
                <span id="currentTime"></span>
            </div>
        </div>
        
        <div class="user-info">
            <span class="user-name">
                <?php echo $viewInstance->escape($userName ?? 'Usuario'); ?>
            </span>
        </div>
        
        <a href="<?php echo $viewInstance->route('logout'); ?>" class="logout-btn">
            Cerrar Sesión
        </a>
    </div>
    
    <script>
        function updateDateTime() {
            const now = new Date();
            const optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const optionsTime = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('es-ES', optionsDate);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('es-ES', optionsTime);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</header>
