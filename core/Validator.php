<?php

namespace SoftNova\Core;

/**
 * Validador de datos
 */

class Validator
{
    private array $data = [];
    private array $errors = [];
    
    public function __construct(array $data)
    {
        $this->data = $data;
    }
    
    /**
     * Validar campo requerido
     */
    public function required(string $field, string $message = null): self
    {
        if (!isset($this->data[$field]) || trim($this->data[$field]) === '') {
            $this->errors[$field] = $message ?? "El campo {$field} es requerido";
        }
        return $this;
    }
    
    /**
     * Validar email
     */
    public function email(string $field, string $message = null): self
    {
        if (isset($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $message ?? "El campo {$field} debe ser un email válido";
        }
        return $this;
    }
    
    /**
     * Validar longitud mínima
     */
    public function minLength(string $field, int $length, string $message = null): self
    {
        if (isset($this->data[$field]) && strlen($this->data[$field]) < $length) {
            $this->errors[$field] = $message ?? "El campo {$field} debe tener al menos {$length} caracteres";
        }
        return $this;
    }
    
    /**
     * Validar longitud máxima
     */
    public function maxLength(string $field, int $length, string $message = null): self
    {
        if (isset($this->data[$field]) && strlen($this->data[$field]) > $length) {
            $this->errors[$field] = $message ?? "El campo {$field} no debe exceder {$length} caracteres";
        }
        return $this;
    }
    
    /**
     * Validar que coincidan dos campos
     */
    public function match(string $field, string $matchField, string $message = null): self
    {
        if (isset($this->data[$field]) && isset($this->data[$matchField]) && 
            $this->data[$field] !== $this->data[$matchField]) {
            $this->errors[$field] = $message ?? "Los campos {$field} y {$matchField} deben coincidir";
        }
        return $this;
    }
    
    /**
     * Verificar si la validación pasó
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }
    
    /**
     * Verificar si la validación falló
     */
    public function fails(): bool
    {
        return !$this->passes();
    }
    
    /**
     * Obtener errores
     */
    public function errors(): array
    {
        return $this->errors;
    }
    
    /**
     * Obtener primer error
     */
    public function firstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }
}
