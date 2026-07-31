<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * Controlador del dashboard principal
 */
class DashboardController extends Controller
{
    public function index(): void
    {
        $this->view('dashboard.index');
    }
}
