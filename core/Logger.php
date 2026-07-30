<?php

namespace SoftNova\Core;

/**
 * Logger centralizado para errores y eventos
 */
class Logger
{
    private static ?Logger $instance = null;
    private string $logFile;
    
    private function __construct()
    {
        $this->logFile = STORAGE_PATH . '/logs/app.log';
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    }
    
    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }
    
    /**
     * Registrar error
     */
    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->log('ERROR', $message, $context);
    }
    
    /**
     * Registrar advertencia
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->log('WARNING', $message, $context);
    }
    
    /**
     * Registrar información
     */
    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->log('INFO', $message, $context);
    }
    
    /**
     * Registrar debug
     */
    public static function debug(string $message, array $context = []): void
    {
        if (config('app.debug', false)) {
            self::getInstance()->log('DEBUG', $message, $context);
        }
    }
    
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $line = "[{$timestamp}] [{$level}] [{$ip}] {$message}{$contextStr}" . PHP_EOL;
        
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
