<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;

/**
 * Sirve archivos de public/uploads/ solo con sesión de tenant.
 */
class TenantMediaController extends TenantController
{
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::auth();
    }

    public function serve(): void
    {
        $rel = \SoftNova\Core\normalize_upload_rel((string)$this->request->get('f', ''));
        if ($rel === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Archivo no encontrado';
            exit;
        }

        $full = PUBLIC_PATH . '/' . $rel;
        if (!is_file($full)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Archivo no encontrado';
            exit;
        }

        $mime = 'application/octet-stream';
        if (class_exists(\finfo::class)) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->file($full);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        } elseif (function_exists('mime_content_type')) {
            $detected = @mime_content_type($full);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
        $name = basename($full);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($full));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
        readfile($full);
        exit;
    }
}
