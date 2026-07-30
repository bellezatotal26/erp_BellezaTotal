<?php
$layout = 'tenant';
$title = 'Soporte - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Tickets de Soporte';
$tenantName = $tenantName ?? 'Mi Empresa'; $userName = $userName ?? 'Usuario';
$tickets = $tickets ?? [];
?>
<?php echo flashMessage(); ?>

<div style="display:flex;gap:15px;margin-bottom:20px;">
    <button onclick="document.getElementById('newTicketModal').style.display='flex'" class="btn btn-primary neumorphic-btn">🎫 Nuevo Ticket</button>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Mis Tickets (<?php echo count($tickets); ?>)</h3></div>
    <div class="card-body">
        <?php if (empty($tickets)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No tienes tickets de soporte</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr><th>Código</th><th>Asunto</th><th>Estado</th><th>Prioridad</th><th>Mensajes</th><th>Fecha</th></tr></thead>
                <tbody><?php foreach($tickets as $t): ?>
                    <tr>
                        <td><a href="<?php echo $viewInstance->route('app/soporte'); ?>?id=<?php echo $t['id'];?>" style="color:var(--color-primary);font-weight:600;"><?php echo htmlspecialchars($t['ticket_code']);?></a></td>
                        <td><?php echo htmlspecialchars($t['subject']);?></td>
                        <td><span class="badge <?php echo $t['status']==='open'?'badge-danger':($t['status']==='in_progress'?'badge-warning':'badge-success');?>"><?php echo $t['status']==='open'?'Abierto':($t['status']==='in_progress'?'En Progreso':$t['status']);?></span></td>
                        <td><span class="badge <?php echo $t['priority']==='urgent'?'badge-danger':($t['priority']==='high'?'badge-warning':'badge-info');?>"><?php echo ucfirst($t['priority']);?></span></td>
                        <td><?php echo $t['msg_count']??0; ?></td>
                        <td><?php echo date('d/m/Y',strtotime($t['created_at']));?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<div id="newTicketModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:500px;">
        <div class="modal-header"><h3>Nuevo Ticket de Soporte</h3><button onclick="document.getElementById('newTicketModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/soporte'); ?>?action=create" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group"><label>Asunto *</label><input type="text" name="subject" class="form-control" required placeholder="Resumen del problema"></div>
                <div class="form-group"><label>Descripción</label><textarea name="description" class="form-control" rows="4" placeholder="Describe tu problema o consulta..."></textarea></div>
                <div class="form-group"><label>Prioridad</label><select name="priority" class="form-control"><option value="low">Baja</option><option value="medium" selected>Media</option><option value="high">Alta</option><option value="urgent">Urgente</option></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('newTicketModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-primary">Enviar Ticket</button></div>
        </form>
    </div>
</div>
