<?php

namespace SoftNova\Services;

use SoftNova\Core\Cache;

/**
 * Caché de settings de la empresa (standalone).
 */
class SettingsCache
{
    private const TTL = 300;
    private const KEY_ALL = 'settings:all';

    public static function all(\PDO $db): array
    {
        return Cache::instance()->remember(self::KEY_ALL, self::TTL, static function () use ($db) {
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $map[$row['setting_key']] = $row['setting_value'];
            }
            return $map;
        });
    }

    public static function get(\PDO $db, string $key, string $default = ''): string
    {
        $all = self::all($db);
        return array_key_exists($key, $all) ? (string)$all[$key] : $default;
    }

    public static function invalidate(): void
    {
        Cache::instance()->forget(self::KEY_ALL);
        Cache::instance()->forget('settings:map');
        Cache::instance()->forgetPrefix('settings');
    }
}
