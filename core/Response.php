<?php

namespace SoftNova\Core;

/**
 * Manejar respuestas HTTP
 */

class Response
{
    /**
     * Establecer código de estado HTTP
     */
    public function status(int $code): self
    {
        http_response_code($code);
        return $this;
    }
    
    /**
     * Redireccionar a una URL
     */
    public function redirect(string $url, int $code = 302): void
    {
        http_response_code($code);
        header("Location: {$url}");
        exit;
    }
    
    /**
     * Retornar respuesta JSON
     */
    public function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Establecer header
     */
    public function header(string $name, string $value): self
    {
        header("{$name}: {$value}");
        return $this;
    }
}
