<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Gastos
 */
class GastosController extends Controller
{
    public function index(): void
    {
        $this->view('gastos.index');
    }
}
