<?php
$layout = 'superadmin';
$title = 'Super Administrador - Tickets de Soporte';
$pageTitle = 'Tickets de Soporte';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$tickets = $tickets ?? [];
$tenants = $tenants ?? [];
$stats = $stats ?? [];
$filters = $filters ?? [];

?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<!-- Estadísticas -->
<div class="stats-grid">
    <div class="stat-card neumorphic">
        <h4>Total Tickets</h4>
        <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Abiertos</h4>
        <div class="stat-value" style="color: #dc2626;"><?php echo number_format($stats['open'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>En Progreso</h4>
        <div class="stat-value" style="color: #f59e0b;"><?php echo number_format($stats['in_progress'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Resueltos</h4>
        <div class="stat-value" style="color: #16a34a;"><?php echo number_format($stats['resolved'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Urgentes</h4>
        <div class="stat-value" style="color: #dc2626;"><?php echo number_format($stats['urgent'] ?? 0); ?></div>
    </div>
</div>

<!-- Filtros -->
<div class="card neumorphic" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 15px;">
        <form method="GET" action="<?php echo $viewInstance->route('superadmin/tickets'); ?>" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label style="font-size: 12px;">Estado</label>
                <select name="status" class="neumorphic-input" style="padding: 8px 12px;">
                    <option value="">Todos</option>
                    <option value="open" <?php echo ($filters['status'] ?? '') === 'open' ? 'selected' : ''; ?>>Abierto</option>
                    <option value="in_progress" <?php echo ($filters['status'] ?? '') === 'in_progress' ? 'selected' : ''; ?>>En Progreso</option>
                    <option value="resolved" <?php echo ($filters['status'] ?? '') === 'resolved' ? 'selected' : ''; ?>>Resuelto</option>
                    <option value="closed" <?php echo ($filters['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Cerrado</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label style="font-size: 12px;">Prioridad</label>
                <select name="priority" class="neumorphic-input" style="padding: 8px 12px;">
                    <option value="">Todas</option>
                    <option value="low" <?php echo ($filters['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>Baja</option>
                    <option value="medium" <?php echo ($filters['priority'] ?? '') === 'medium' ? 'selected' : ''; ?>>Media</option>
                    <option value="high" <?php echo ($filters['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>Alta</option>
                    <option value="urgent" <?php echo ($filters['priority'] ?? '') === 'urgent' ? 'selected' : ''; ?>>Urgente</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label style="font-size: 12px;">Cliente</label>
                <select name="tenant_id" class="neumorphic-input" style="padding: 8px 12px;">
                    <option value="">Todos</option>
                    <?php foreach ($tenants as $tenant): ?>
                        <option value="<?php echo $tenant['id']; ?>" <?php echo ($filters['tenant_id'] ?? '') == $tenant['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tenant['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 8px 20px;">Filtrar</button>
            <?php if (!empty($filters['status']) || !empty($filters['priority']) || !empty($filters['tenant_id'])): ?>
                <a href="<?php echo $viewInstance->route('superadmin/tickets'); ?>" class="btn btn-secondary" style="padding: 8px 20px;">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header">
        <h3>Lista de Tickets</h3>
        <button onclick="document.getElementById('createTicketForm').style.display='flex'" class="btn btn-primary neumorphic-btn">
            Nuevo Ticket
        </button>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Cliente</th>
                        <th>Asunto</th>
                        <th>Categoría</th>
                        <th>Prioridad</th>
                        <th>Estado</th>
                        <th>Mensajes</th>
                        <th>Creado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--color-text-secondary);">
                                No hay tickets registrados
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ticket['ticket_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(mb_strlen($ticket['subject']) > 50 ? mb_substr($ticket['subject'], 0, 50) . '...' : $ticket['subject']); ?></td>
                                <td><?php echo ticketCategoryLabel($ticket['category']); ?></td>
                                <td>
                                    <span class="badge <?php echo ticketPriorityBadgeClass($ticket['priority']); ?>">
                                        <?php echo ticketPriorityLabel($ticket['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo ticketStatusBadgeClass($ticket['status']); ?>">
                                        <?php echo ticketStatusLabel($ticket['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $ticket['message_count']; ?></td>
                                <td><?php echo timeAgo($ticket['created_at']); ?></td>
                                <td class="table-actions">
                                    <a href="<?php echo $viewInstance->route('superadmin/tickets/view/' . $ticket['id']); ?>" class="btn btn-success" title="Ver conversación">Chat</a>
                                    <form method="POST" action="<?php echo $viewInstance->route('superadmin/tickets'); ?>?action=update_status" style="display:inline;" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $ticket['id']; ?>">
                                        <input type="hidden" name="status" value="<?php echo $ticket['status'] === 'open' ? 'in_progress' : ($ticket['status'] === 'in_progress' ? 'resolved' : 'open'); ?>">
                                        <button type="submit" class="btn <?php echo $ticket['status'] === 'open' ? 'btn-warning' : ($ticket['status'] === 'in_progress' ? 'btn-success' : 'btn-info'); ?>" title="Cambiar estado">
                                            <?php echo $ticket['status'] === 'open' ? 'Procesar' : ($ticket['status'] === 'in_progress' ? 'Resolver' : 'Reabrir'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Crear Ticket -->
<div id="createTicketForm" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Nuevo Ticket de Soporte</h3>
            <button onclick="document.getElementById('createTicketForm').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('superadmin/tickets'); ?>?action=create" class="settings-form" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="form-group">
                <label>Cliente *</label>
                <select name="tenant_id" required class="neumorphic-input">
                    <option value="">Seleccione un cliente</option>
                    <?php foreach ($tenants as $tenant): ?>
                        <option value="<?php echo $tenant['id']; ?>"><?php echo htmlspecialchars($tenant['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Asunto *</label>
                <input type="text" name="subject" required class="neumorphic-input" placeholder="Resumen del problema o consulta">
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="description" rows="4" class="neumorphic-input" placeholder="Detalle del problema o consulta"></textarea>
            </div>
            <div class="form-group">
                <label>Prioridad</label>
                <select name="priority" class="neumorphic-input">
                    <option value="low">Baja</option>
                    <option value="medium" selected>Media</option>
                    <option value="high">Alta</option>
                    <option value="urgent">Urgente</option>
                </select>
            </div>
            <div class="form-group">
                <label>Categoría</label>
                <select name="category" class="neumorphic-input">
                    <option value="support">Soporte</option>
                    <option value="consultation">Consulta</option>
                    <option value="bug">Error</option>
                    <option value="feature_request">Solicitud de Funcionalidad</option>
                    <option value="other">Otro</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary neumorphic-btn">Crear Ticket</button>
        </form>
    </div>
</div>

<!-- Logs de Actividad de Clientes -->
<?php $activityLogs = $activityLogs ?? []; ?>
<div class="card neumorphic" style="margin-top:20px;">
    <div class="card-header"><h3>📊 Logs de Actividad de Clientes</h3></div>
    <div class="card-body">
        <?php if (empty($activityLogs)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Sin actividad registrada</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr><th>Fecha</th><th>Cliente</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Descripción</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($activityLogs as $log):
                    $badgeMap = ['create'=>'badge-success','update'=>'badge-info','delete'=>'badge-danger','login'=>'badge-success','logout'=>'badge-warning'];
                ?>
                    <tr>
                        <td style="font-size:12px;"><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($log['tenant_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['user_name'] ?? '-'); ?></td>
                        <td><span class="badge <?php echo $badgeMap[$log['action']]??'badge-info';?>"><?php echo $log['action'];?></span></td>
                        <td><?php echo htmlspecialchars($log['module']??'-');?></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($log['description']??'-');?></td>
                        <td style="font-size:11px;"><?php echo htmlspecialchars($log['ip_address']??'-');?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>