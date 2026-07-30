<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Reportes
 */
class ReportesController extends Controller
{
    public function index(): void
    {
        $this->view('reportes.index');
    }
}
