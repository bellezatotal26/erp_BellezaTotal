<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Clientes
 */
class ClientesController extends Controller
{
    public function index(): void
    {
        $this->view('clientes.index');
    }
}
