<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Contabilidad
 */
class ContabilidadController extends Controller
{
    public function index(): void
    {
        $this->view('contabilidad.index');
    }
}
