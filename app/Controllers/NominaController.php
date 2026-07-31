<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Nómina
 */
class NominaController extends Controller
{
    public function index(): void
    {
        $this->view('nomina.index');
    }
}
