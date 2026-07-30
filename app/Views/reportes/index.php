<?php
$layout = 'main';
$title = 'Reportes - Seri ERP';
$pageTitle = 'Analíticas y Reportes';
$userName = 'Usuario';
?>

<div class="stats-grid">
    <div class="stat-card">
        <h4>Ventas del Mes</h4>
        <div class="stat-value">$0.00</div>
    </div>
    
    <div class="stat-card">
        <h4>Gastos del Mes</h4>
        <div class="stat-value">$0.00</div>
    </div>
    
    <div class="stat-card">
        <h4>Utilidad del Mes</h4>
        <div class="stat-value">$0.00</div>
    </div>
    
    <div class="stat-card">
        <h4>Margen de Utilidad</h4>
        <div class="stat-value">0%</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Reportes Disponibles</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Reporte</th>
                        <th>Descripción</th>
                        <th>Periodo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Reporte de Ventas</td>
                        <td>Análisis detallado de ventas por periodo</td>
                        <td>Mensual</td>
                        <td>
                            <button class="btn btn-primary">Generar</button>
                        </td>
                    </tr>
                    <tr>
                        <td>Reporte de Inventario</td>
                        <td>Estado actual del inventario</td>
                        <td>Actual</td>
                        <td>
                            <button class="btn btn-primary">Generar</button>
                        </td>
                    </tr>
                    <tr>
                        <td>Reporte de Gastos</td>
                        <td>Análisis de gastos y salidas</td>
                        <td>Mensual</td>
                        <td>
                            <button class="btn btn-primary">Generar</button>
                        </td>
                    </tr>
                    <tr>
                        <td>Estado de Resultados</td>
                        <td>Reporte contable de ingresos y egresos</td>
                        <td>Mensual</td>
                        <td>
                            <button class="btn btn-primary">Generar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
