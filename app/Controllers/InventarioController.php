<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Inventario
 */
class InventarioController extends Controller
{
    public function index(): void
    {
        $this->view('inventario.index');
    }
}
