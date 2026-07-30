<?php
$layout = 'superadmin';
$title = 'Super Administrador - Noticias';
$pageTitle = 'Noticias / Anuncios';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$announcements = $announcements ?? [];
?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<p style="color:var(--color-text-secondary);margin-bottom:16px;font-size:14px;">
    Los anuncios activos aparecen en la campana de notificaciones de todos los clientes (panel tenant).
</p>

<div class="card neumorphic" style="margin-bottom:20px;">
    <div class="card-header"><h3>Publicar noticia</h3></div>
    <div class="card-body">
        <form method="POST" action="<?php echo $viewInstance->route('superadmin/announcements'); ?>?action=create" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="form-group">
                <label>Titulo *</label>
                <input type="text" name="title" class="form-control neumorphic-input" required maxlength="255" title="Titulo">
            </div>
            <div class="form-group">
                <label>Mensaje *</label>
                <textarea name="body" class="form-control neumorphic-input" rows="4" required title="Mensaje"></textarea>
            </div>
            <div class="form-group">
                <label>Prioridad</label>
                <select name="priority" class="form-control neumorphic-input" title="Prioridad">
                    <option value="normal">Normal</option>
                    <option value="important">Importante</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary neumorphic-btn" title="Publicar">Publicar</button>
        </form>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Anuncios publicados</h3></div>
    <div class="card-body">
        <?php if (empty($announcements)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay anuncios aun</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr><th>Titulo</th><th>Prioridad</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($announcements as $a): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                            <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px;">
                                <?php echo htmlspecialchars(mb_substr($a['body'], 0, 100)); ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo ($a['priority'] ?? '') === 'important' ? 'badge-danger' : 'badge-info'; ?>">
                                <?php echo ($a['priority'] ?? '') === 'important' ? 'Importante' : 'Normal'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo ($a['status'] ?? '') === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo ($a['status'] ?? '') === 'active' ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td><?php echo date('d/m/Y H:i', strtotime($a['published_at'] ?? $a['created_at'])); ?></td>
                        <td class="table-actions">
                            <form method="POST" action="<?php echo $viewInstance->route('superadmin/announcements'); ?>?action=toggle" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-secondary" title="Activar/Desactivar">Estado</button>
                            </form>
                            <form method="POST" action="<?php echo $viewInstance->route('superadmin/announcements'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" onclick="return confirm('Eliminar anuncio?')" class="btn btn-sm btn-danger" title="Eliminar">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
