<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Proveedores
 */
class ProveedoresController extends Controller
{
    public function index(): void
    {
        $this->view('proveedores.index');
    }
}
