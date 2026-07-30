<?php
$layout = 'main';
$title = 'Inventario - Seri ERP';
$pageTitle = 'Inventario';
$userName = 'Usuario';
?>

<div class="card">
    <div class="card-header">
        <h3>Gestión de Inventario</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Stock</th>
                        <th>Precio</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--color-text-secondary);">
                            No hay productos en inventario
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
