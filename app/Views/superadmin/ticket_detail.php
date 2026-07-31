<?php
$layout = 'superadmin';
$title = 'Ticket #' . ($ticket['ticket_code'] ?? '') . ' - Detalle';
$pageTitle = 'Ticket: ' . htmlspecialchars($ticket['subject'] ?? '');
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$ticket = $ticket ?? [];
$messages = $messages ?? [];
$tenants = $tenants ?? [];

?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px;">
    <a href="<?php echo $viewInstance->route('superadmin/tickets'); ?>" class="btn btn-secondary">&larr; Volver a Tickets</a>
</div>

<!-- Info del Ticket -->
<div class="card neumorphic" style="margin-bottom: 20px;">
    <div class="card-body">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="margin: 0 0 10px 0; color: var(--color-primary);"><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <span class="badge <?php echo ticketStatusBadgeClass($ticket['status']); ?>"><?php echo ticketStatusLabel($ticket['status']); ?></span>
                    <span class="badge <?php echo ticketPriorityBadgeClass($ticket['priority']); ?>"><?php echo ticketPriorityLabel($ticket['priority']); ?></span>
                    <span style="color: var(--color-text-secondary); font-size: 13px;">
                        Código: <strong><?php echo htmlspecialchars($ticket['ticket_code']); ?></strong>
                    </span>
                    <span style="color: var(--color-text-secondary); font-size: 13px;">
                        Cliente: <strong><?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?></strong>
                    </span>
                    <span style="color: var(--color-text-secondary); font-size: 13px;">
                        Creado: <strong><?php echo formatDateTime($ticket['created_at']); ?></strong>
                    </span>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <form method="POST" action="<?php echo $viewInstance->route('superadmin/tickets'); ?>?action=update_status" data-ajax="true" style="display:inline;">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $ticket['id']; ?>">
                    <select name="status" onchange="this.form.submit()" class="neumorphic-input" style="padding: 8px 12px; width: auto;">
                        <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Abierto</option>
                        <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>En Progreso</option>
                        <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resuelto</option>
                        <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>>Cerrado</option>
                    </select>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Chat de Mensajes -->
<div class="card neumorphic" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Conversación (<?php echo count($messages); ?> mensajes)</h3>
    </div>
    <div class="card-body">
        <div class="ticket-chat" style="max-height: 500px; overflow-y: auto; padding: 10px;">
            <?php if (empty($messages)): ?>
                <p style="text-align: center; color: var(--color-text-secondary);">No hay mensajes aún.</p>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="chat-message <?php echo $msg['is_staff'] ? 'chat-staff' : 'chat-client'; ?>" style="margin-bottom: 15px; display: flex; flex-direction: column; align-items: <?php echo $msg['is_staff'] ? 'flex-end' : 'flex-start'; ?>;">
                        <div style="max-width: 70%; padding: 12px 16px; border-radius: 16px; <?php echo $msg['is_staff'] ? 'background: linear-gradient(135deg, #3b82f6, #06b6d4); color: #fff;' : 'background: var(--color-secondary); border: 1px solid var(--color-border);'; ?>">
                            <div style="font-size: 12px; font-weight: 600; margin-bottom: 4px; <?php echo $msg['is_staff'] ? 'color: rgba(255,255,255,0.8);' : 'color: var(--color-primary);'; ?>">
                                <?php echo htmlspecialchars($msg['user_name']); ?>
                                <?php if ($msg['is_staff']): ?>
                                    <span style="font-size: 10px; background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 8px; margin-left: 5px;">Staff</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 14px; line-height: 1.5; white-space: pre-wrap;"><?php echo htmlspecialchars($msg['message']); ?></div>
                            <div style="font-size: 10px; margin-top: 6px; <?php echo $msg['is_staff'] ? 'color: rgba(255,255,255,0.6);' : 'color: var(--color-text-secondary);'; ?>">
                                <?php echo formatDateTime($msg['created_at']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Formulario de respuesta -->
        <form method="POST" action="<?php echo $viewInstance->route('superadmin/tickets'); ?>?action=message" data-ajax="true" style="margin-top: 20px; border-top: 1px solid var(--color-border); padding-top: 20px;">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
            <div class="form-group">
                <label>Responder</label>
                <textarea name="message" rows="3" required class="neumorphic-input" placeholder="Escribe tu respuesta..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary neumorphic-btn">Enviar Mensaje</button>
        </form>
    </div>
</div>

<script>
// Auto-scroll chat to bottom
document.addEventListener('DOMContentLoaded', function() {
    const chatContainer = document.querySelector('.ticket-chat');
    if (chatContainer) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }
});
</script>