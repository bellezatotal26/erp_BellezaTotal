<?php

namespace SoftNova\Core;

/**
 * Caché file + Redis opcional (si extensión redis está disponible).
 */
class Cache
{
    private string $cacheDir;
    private static ?self $instance = null;
    private $redis = null;
    private bool $redisTried = false;

    public function __construct()
    {
        $this->cacheDir = STORAGE_PATH . '/cache/';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function redis()
    {
        if ($this->redisTried) {
            return $this->redis;
        }
        $this->redisTried = true;
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            $r = new \Redis();
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            if (!$r->connect($host, $port, 0.5)) {
                return null;
            }
            $pass = getenv('REDIS_PASSWORD') ?: '';
            if ($pass !== '') {
                $r->auth($pass);
            }
            $db = (int)(getenv('REDIS_DB') ?: 0);
            if ($db > 0) {
                $r->select($db);
            }
            $this->redis = $r;
        } catch (\Throwable $e) {
            $this->redis = null;
        }
        return $this->redis;
    }

    private function redisKey(string $key): string
    {
        $prefix = getenv('REDIS_PREFIX') ?: 'seri:';
        return $prefix . $key;
    }

    public function get(string $key, $default = null)
    {
        $redis = $this->redis();
        if ($redis) {
            try {
                $raw = $redis->get($this->redisKey($key));
                if ($raw === false || $raw === null) {
                    return $default;
                }
                $decoded = json_decode($raw, true);
                return array_key_exists('v', (array)$decoded) ? $decoded['v'] : $default;
            } catch (\Throwable $e) {
                // fallback file
            }
        }

        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return $default;
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!$data || ($data['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return $default;
        }
        return $data['value'] ?? $default;
    }

    public function set(string $key, $value, int $ttlSeconds = 300): void
    {
        $redis = $this->redis();
        if ($redis) {
            try {
                $redis->setex($this->redisKey($key), max(1, $ttlSeconds), json_encode(['v' => $value]));
            } catch (\Throwable $e) {
                // continue to file
            }
        }

        $file = $this->getFilePath($key);
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    public function forget(string $key): void
    {
        $redis = $this->redis();
        if ($redis) {
            try {
                $redis->del($this->redisKey($key));
            } catch (\Throwable $e) {
                // ignore
            }
        }
        @unlink($this->getFilePath($key));
    }

    public function forgetPrefix(string $prefix): int
    {
        $count = 0;
        foreach (glob($this->cacheDir . '*.cache') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            // file keys are hashed; also wipe settings-* via known keys
            if (is_array($data) && str_starts_with((string)($data['key'] ?? ''), $prefix)) {
                @unlink($file);
                $count++;
            }
        }
        // Known settings keys
        if ($prefix === 'settings' || str_starts_with($prefix, 'settings')) {
            $this->forget('settings:all');
            $this->forget('settings:map');
            $count++;
        }
        return $count;
    }

    public function remember(string $key, int $ttlSeconds, callable $callback)
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value, $ttlSeconds);
        return $value;
    }

    public function clean(): int
    {
        $count = 0;
        foreach (glob($this->cacheDir . '*.cache') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if ($data && ($data['expires_at'] ?? 0) < time()) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }

    private function getFilePath(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }
}
