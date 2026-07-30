<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Caja
 */
class CajaController extends Controller
{
    public function index(): void
    {
        $this->view('caja.index');
    }
}
