<?php
$layout = 'tenant';
$title = 'Ticket ' . ($ticket['ticket_code']??'') . ' - Soporte';
$pageTitle = 'Chat de Soporte';
$tenantName = $tenantName ?? ''; $userName = $userName ?? ''; $ticket = $ticket ?? []; $messages = $messages ?? [];
?>
<a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="btn btn-secondary" style="margin-bottom:15px;">← Volver a Tickets</a>

<div class="card neumorphic" style="margin-bottom:15px;">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h3 style="margin:0;"><?php echo htmlspecialchars($ticket['subject']??'');?></h3>
                <span style="color:var(--color-text-secondary);font-size:13px;"><?php echo htmlspecialchars($ticket['ticket_code']??'');?> · Creado: <?php echo date('d/m/Y H:i',strtotime($ticket['created_at']??'now'));?></span>
            </div>
            <span class="badge <?php echo ($ticket['status']??'open')==='open'?'badge-danger':(($ticket['status']??'')==='in_progress'?'badge-warning':'badge-success');?>"><?php echo ($ticket['status']??'open')==='open'?'Abierto':(($ticket['status']??'')==='in_progress'?'En Progreso':ucfirst($ticket['status']??''));?></span>
        </div>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:15px;">
    <div class="card-header"><h3>Conversación (<?php echo count($messages); ?> mensajes)</h3></div>
    <div class="card-body">
        <div class="ticket-chat" style="max-height:450px;overflow-y:auto;padding:10px;">
            <?php if(empty($messages)): ?>
                <p style="text-align:center;color:var(--color-text-secondary);">No hay mensajes aún.</p>
            <?php else: ?>
                <?php foreach($messages as $msg): ?>
                    <div class="chat-message <?php echo $msg['is_staff']?'chat-staff':'chat-client';?>" style="margin-bottom:15px;display:flex;flex-direction:column;align-items:<?php echo $msg['is_staff']?'flex-end':'flex-start';?>;">
                        <div style="max-width:70%;padding:12px 16px;border-radius:16px;<?php echo $msg['is_staff']?'background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#fff;':'background:var(--bg-input);border:1px solid var(--color-border);';?>">
                            <div style="font-size:12px;font-weight:600;margin-bottom:4px;<?php echo $msg['is_staff']?'color:rgba(255,255,255,0.8);':'color:var(--color-primary);';?>">
                                <?php echo htmlspecialchars($msg['user_name']);?>
                                <?php if($msg['is_staff']): ?><span style="font-size:10px;background:rgba(255,255,255,0.2);padding:2px 6px;border-radius:8px;margin-left:5px;">Soporte</span><?php endif; ?>
                            </div>
                            <div style="font-size:14px;line-height:1.5;white-space:pre-wrap;"><?php echo htmlspecialchars($msg['message']);?></div>
                            <div style="font-size:10px;margin-top:6px;<?php echo $msg['is_staff']?'color:rgba(255,255,255,0.6);':'color:var(--color-text-secondary);';?>"><?php echo date('d/m/Y H:i',strtotime($msg['created_at']));?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <form method="POST" action="<?php echo $viewInstance->route('app/soporte'); ?>?action=reply" data-ajax="true" style="margin-top:20px;border-top:1px solid var(--color-border);padding-top:20px;">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="ticket_id" value="<?php echo $ticket['id'];?>">
            <div class="form-group"><label>Responder</label><textarea name="message" rows="3" required class="form-control" placeholder="Escribe tu respuesta..."></textarea></div>
            <button type="submit" class="btn btn-primary neumorphic-btn">📨 Enviar Mensaje</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    const chat = document.querySelector('.ticket-chat');
    if(chat) chat.scrollTop = chat.scrollHeight;
});
</script>
