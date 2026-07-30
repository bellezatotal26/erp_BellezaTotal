<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Ventas
 */
class VentasController extends Controller
{
    public function index(): void
    {
        $this->view('ventas.index');
    }
}
