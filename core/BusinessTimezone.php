<?php

namespace SoftNova\Core;

/**
 * Zona horaria de negocio (fecha/hora contable).
 * Fuente de verdad: settings.timezone → config app.timezone → America/Bogota.
 */
class BusinessTimezone
{
    public const DEFAULT = 'America/Bogota';
    public const SETTING_KEY = 'timezone';

    /** @return array<string,string> IANA => etiqueta */
    public static function options(): array
    {
        return [
            'America/Bogota' => 'Colombia — Bogotá (UTC−5, sin DST)',
            'America/Lima' => 'Perú — Lima (UTC−5)',
            'America/Guayaquil' => 'Ecuador — Guayaquil (UTC−5)',
            'America/Panama' => 'Panamá (UTC−5)',
            'America/Caracas' => 'Venezuela — Caracas (UTC−4)',
            'America/La_Paz' => 'Bolivia — La Paz (UTC−4)',
            'America/Santiago' => 'Chile — Santiago',
            'America/Argentina/Buenos_Aires' => 'Argentina — Buenos Aires',
            'America/Mexico_City' => 'México — Ciudad de México',
            'America/New_York' => 'EE.UU. — Nueva York',
            'UTC' => 'UTC',
        ];
    }

    public static function normalize(?string $tz): string
    {
        $tz = trim((string)$tz);
        if ($tz === '' || @timezone_open($tz) === false) {
            return self::DEFAULT;
        }
        return $tz;
    }

    public static function fromConfig(): string
    {
        return self::normalize((string)config('app.timezone', self::DEFAULT));
    }

    public static function fromSettings(?\PDO $db): string
    {
        if ($db === null) {
            return self::fromConfig();
        }
        try {
            if (class_exists(\SoftNova\Services\SettingsCache::class)) {
                $val = \SoftNova\Services\SettingsCache::get($db, self::SETTING_KEY, '');
                if ($val !== '') {
                    return self::normalize($val);
                }
            }
            $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::SETTING_KEY]);
            $val = (string)($stmt->fetchColumn() ?: '');
            if ($val !== '') {
                return self::normalize($val);
            }
        } catch (\Throwable $e) {
            // settings aún no disponible
        }
        return self::fromConfig();
    }

    public static function resolve(?\PDO $db = null): string
    {
        return $db ? self::fromSettings($db) : self::fromConfig();
    }

    public static function applyPhp(?string $tz = null): string
    {
        $tz = self::normalize($tz ?? self::fromConfig());
        date_default_timezone_set($tz);
        try {
            if (function_exists('ini_set')) {
                @ini_set('date.timezone', $tz);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $tz;
    }

    public static function mysqlOffset(string $tz): string
    {
        $tz = self::normalize($tz);
        return (new \DateTime('now', new \DateTimeZone($tz)))->format('P');
    }

    public static function mysqlInitCommand(?string $tz = null): string
    {
        $tz = self::normalize($tz ?? self::fromConfig());
        $offset = str_replace("'", '', self::mysqlOffset($tz));
        return "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '{$offset}'";
    }

    public static function applyToPdo(\PDO $pdo, ?string $tz = null): void
    {
        $tz = self::normalize($tz ?? self::fromConfig());
        $offset = str_replace("'", '', self::mysqlOffset($tz));
        try {
            $pdo->exec("SET time_zone = '{$offset}'");
        } catch (\Throwable $e) {
            error_log('BusinessTimezone MySQL: ' . $e->getMessage());
        }
    }

    /**
     * Aplica PHP + MySQL desde settings del tenant (una vez por petición vía middleware).
     */
    public static function applyFromTenantDb(\PDO $db): string
    {
        $tz = self::fromSettings($db);
        self::applyPhp($tz);
        self::applyToPdo($db, $tz);
        return $tz;
    }

    public static function today(?\PDO $db = null): string
    {
        self::applyPhp(self::resolve($db));
        return date('Y-m-d');
    }

    public static function now(?\PDO $db = null): string
    {
        self::applyPhp(self::resolve($db));
        return date('Y-m-d H:i:s');
    }

    /** Si el cliente manda un día futuro (bug UTC), usa hoy de negocio. */
    public static function clampBusinessDate(string $ymd, ?\PDO $db = null): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return self::today($db);
        }
        $today = self::today($db);
        return $ymd > $today ? $today : $ymd;
    }

    /** Payload para layouts JS (día/hora de negocio). */
    public static function clientPayload(?\PDO $db = null): array
    {
        $tz = self::resolve($db);
        self::applyPhp($tz);
        return [
            'timezone' => $tz,
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
            'now' => date('Y-m-d H:i:s'),
            'label' => self::options()[$tz] ?? $tz,
        ];
    }
}
