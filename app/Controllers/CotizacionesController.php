<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del módulo Cotizaciones
 */
class CotizacionesController extends Controller
{
    public function index(): void
    {
        $this->view('cotizaciones.index');
    }
}
