<?php

namespace SoftNova\Services;

/**
 * Modo caja de arena: clona la BD de producción a un espejo y enruta
 * la sesión del admin hacia ese espejo. Al salir, producción queda intacta.
 */
class SandboxService
{
    public const SESSION_FLAG = 'sandbox_mode';
    public const SESSION_DB = 'sandbox_db_name';
    public const MARKER_KEY = 'sandbox_environment';

    /**
     * @return array{active:bool,db_name:string,production_db:string,sandbox_db:string,sandbox_exists:bool,cloned_at:?string}
     */
    public static function status(): array
    {
        $prod = self::productionDbName();
        $sandbox = self::sandboxDbName();
        $exists = self::databaseExists($sandbox);
        $clonedAt = null;
        if ($exists) {
            $clonedAt = self::readMarkerValue($sandbox, 'sandbox_cloned_at');
        }

        return [
            'active' => self::isActive(),
            'db_name' => self::activeDbName(),
            'production_db' => $prod,
            'sandbox_db' => $sandbox,
            'sandbox_exists' => $exists,
            'cloned_at' => $clonedAt,
        ];
    }

    public static function isActive(): bool
    {
        return !empty($_SESSION[self::SESSION_FLAG])
            && trim((string)($_SESSION[self::SESSION_DB] ?? '')) !== '';
    }

    public static function productionDbName(): string
    {
        if (!\SoftNova\Core\is_standalone()) {
            $fromSession = preg_replace(
                '/[^a-zA-Z0-9_]/',
                '',
                (string)($_SESSION['tenant_db_name_production'] ?? $_SESSION['tenant_db_name'] ?? '')
            );
            if ($fromSession !== '') {
                return $fromSession;
            }
        }
        return preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            (string)\SoftNova\Core\config('database.default.database', '')
        ) ?: '';
    }

    public static function sandboxDbName(): string
    {
        $override = preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            (string)\SoftNova\Core\config('database.default.sandbox_database', '')
        );
        if ($override !== '') {
            return $override;
        }

        $prod = self::productionDbName();
        if ($prod === '') {
            return '';
        }
        $candidate = $prod . '_sandbox';
        if (strlen($candidate) > 64) {
            $candidate = substr($prod, 0, 56) . '_sb';
        }
        return $candidate;
    }

    /** BD a la que debe apuntar la app en esta petición. */
    public static function activeDbName(): string
    {
        if (self::isActive()) {
            $sb = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_SESSION[self::SESSION_DB]);
            if ($sb !== '') {
                return $sb;
            }
        }
        return self::productionDbName();
    }

    /**
     * Clona producción → espejo y activa el modo en la sesión actual.
     *
     * @return array{success:bool,message:string,status?:array}
     */
    public static function enter(bool $refresh = true): array
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $prod = self::productionDbName();
        $sandbox = self::sandboxDbName();
        if ($prod === '' || $sandbox === '') {
            return ['success' => false, 'message' => 'No se pudo determinar el nombre de la base de datos'];
        }
        if (strcasecmp($prod, $sandbox) === 0) {
            return ['success' => false, 'message' => 'La BD de producción y la de sandbox no pueden ser la misma'];
        }

        // Evitar clonar desde el propio espejo.
        if (self::isActive()) {
            self::clearSessionFlags();
            \SoftNova\Core\Database::getInstance()->reconnect();
        }

        if (self::readMarkerValue($prod, self::MARKER_KEY) === '1') {
            return [
                'success' => false,
                'message' => 'La base configurada como producción parece ser un espejo de pruebas. Abortado por seguridad.',
            ];
        }

        $exists = self::databaseExists($sandbox);
        if ($refresh || !$exists) {
            $clone = self::cloneDatabase($prod, $sandbox);
            if (empty($clone['success'])) {
                return $clone;
            }
        }

        $_SESSION[self::SESSION_FLAG] = 1;
        $_SESSION[self::SESSION_DB] = $sandbox;
        if (!\SoftNova\Core\is_standalone() && empty($_SESSION['tenant_db_name_production'])) {
            $_SESSION['tenant_db_name_production'] = $prod;
        }

        \SoftNova\Core\Database::getInstance()->reconnect();
        try {
            \SoftNova\Core\TenantMiddleware::refreshDb();
        } catch (\Throwable $e) {
            // standalone usa Database; refreshDb también.
        }

        $viaPhp = !self::shellExecAvailable();
        $cloneNote = $viaPhp
            ? ' (clonado por PHP; puede tardar 1–3 min en hosting compartido)'
            : '';
        return [
            'success' => true,
            'message' => ($refresh || !$exists
                ? 'Modo caja de arena activo. Se clonó la base de producción; las pruebas no afectarán datos reales.'
                : 'Modo caja de arena activo (espejo existente).') . $cloneNote,
            'status' => self::status(),
        ];
    }

    /**
     * Sale del modo pruebas y vuelve a producción.
     *
     * @return array{success:bool,message:string,status?:array}
     */
    public static function exitMode(bool $dropSandbox = false): array
    {
        $sandbox = self::sandboxDbName();
        self::clearSessionFlags();
        \SoftNova\Core\Database::getInstance()->reconnect();
        try {
            \SoftNova\Core\TenantMiddleware::refreshDb();
        } catch (\Throwable $e) {
            // ignore
        }

        $extra = '';
        if ($dropSandbox && $sandbox !== '') {
            $drop = self::dropDatabase($sandbox);
            $extra = !empty($drop['success'])
                ? ' Espejo eliminado.'
                : (' ' . ($drop['message'] ?? 'No se pudo eliminar el espejo.'));
        }

        return [
            'success' => true,
            'message' => 'Volvió a producción. Los movimientos de la caja de arena no se aplicaron a los datos reales.' . $extra,
            'status' => self::status(),
        ];
    }

    public static function clearSessionFlags(): void
    {
        unset($_SESSION[self::SESSION_FLAG], $_SESSION[self::SESSION_DB]);
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function cloneDatabase(string $source, string $target): array
    {
        $cfg = \SoftNova\Core\config('database.default', []) ?: [];
        $host = (string)($cfg['host'] ?? 'localhost');
        $port = (int)($cfg['port'] ?? 3306);
        $user = (string)($cfg['username'] ?? '');
        $pass = (string)($cfg['password'] ?? '');
        $charset = (string)($cfg['charset'] ?? 'utf8mb4');

        try {
            $server = new \PDO(
                sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
                $user,
                $pass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo conectar al servidor MySQL: ' . $e->getMessage()];
        }

        $safeTarget = str_replace('`', '``', $target);
        $canUseTarget = self::canUseDatabase($target, $cfg);

        // Hosting compartido: casi nunca permite CREATE DATABASE.
        // Si la BD espejo ya existe y el usuario tiene acceso, solo se vacía y clona.
        if ($canUseTarget) {
            $empty = self::emptyDatabase($target, $server);
            if (empty($empty['success'])) {
                return $empty;
            }
        } else {
            try {
                $server->exec(
                    'CREATE DATABASE `' . $safeTarget . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
                $canUseTarget = self::canUseDatabase($target, $cfg);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => self::hostingCreateHelpMessage($target, (string)($cfg['username'] ?? ''), $e->getMessage()),
                ];
            }
            if (!$canUseTarget) {
                return [
                    'success' => false,
                    'message' => self::hostingCreateHelpMessage(
                        $target,
                        (string)($cfg['username'] ?? ''),
                        'La base existe o se creó, pero el usuario no tiene privilegios sobre ella.'
                    ),
                ];
            }
        }

        $dump = self::dumpAndRestore($source, $target, $cfg);
        if (empty($dump['success'])) {
            return $dump;
        }

        self::writeMarkers($target, $server);

        return [
            'success' => true,
            'message' => 'Espejo clonado correctamente',
        ];
    }

    /** Mensaje accionable cuando el hosting no permite crear la BD espejo. */
    private static function hostingCreateHelpMessage(string $target, string $dbUser, string $detail): string
    {
        $user = $dbUser !== '' ? $dbUser : 'el usuario MySQL de la app';
        return 'No se pudo preparar la BD espejo «' . $target . '». '
            . 'En el panel del hosting (cPanel → Bases de datos MySQL): '
            . '1) Cree la base «' . $target . '». '
            . '2) Asigne el usuario «' . $user . '» a esa base con TODOS los privilegios. '
            . '3) Vuelva aquí y active de nuevo la caja de arena. '
            . 'Detalle: ' . $detail;
    }

    /**
     * ¿El usuario de la app puede usar esa BD? (más fiable que solo INFORMATION_SCHEMA).
     *
     * @param array<string,mixed> $cfg
     */
    private static function canUseDatabase(string $dbName, array $cfg): bool
    {
        if ($dbName === '') {
            return false;
        }
        try {
            $pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $cfg['host'] ?? 'localhost',
                    (int)($cfg['port'] ?? 3306),
                    $dbName,
                    $cfg['charset'] ?? 'utf8mb4'
                ),
                (string)($cfg['username'] ?? ''),
                (string)($cfg['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $pdo->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Hosting compartido suele deshabilitar exec/shell_exec (disable_functions). */
    private static function shellExecAvailable(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $disabled = \strtolower((string)\ini_get('disable_functions'));
        if ($disabled === '') {
            return true;
        }
        $list = \array_map('trim', \explode(',', $disabled));
        return !\in_array('exec', $list, true);
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array{success:bool,message:string}
     */
    private static function dumpAndRestore(string $source, string $target, array $cfg): array
    {
        // cPanel / hosting: sin exec → clonar tablas por PDO (mismo servidor MySQL).
        if (!self::shellExecAvailable()) {
            return self::cloneViaPhp($source, $target, $cfg);
        }

        $mysqldump = self::findBin('mysqldump');
        $mysql = self::findBin('mysql');
        if ($mysqldump && $mysql) {
            $host = (string)($cfg['host'] ?? 'localhost');
            $port = (int)($cfg['port'] ?? 3306);
            $user = (string)($cfg['username'] ?? '');
            $pass = (string)($cfg['password'] ?? '');
            $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'seri_sandbox_' . preg_replace('/[^a-zA-Z0-9_]/', '', $source) . '_' . getmypid() . '.sql';

            $dumpCmd = sprintf(
                '"%s" --single-transaction --routines --triggers --quick -h %s -P %s -u %s %s %s > %s 2>&1',
                $mysqldump,
                escapeshellarg($host),
                escapeshellarg((string)$port),
                escapeshellarg($user),
                $pass !== '' ? '-p' . escapeshellarg($pass) : '',
                escapeshellarg($source),
                escapeshellarg($tmp)
            );
            $out = [];
            $code = 0;
            \exec($dumpCmd, $out, $code);
            if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 50) {
                @unlink($tmp);
                return [
                    'success' => false,
                    'message' => 'Error al volcar producción: ' . implode(' ', $out),
                ];
            }

            $restoreCmd = sprintf(
                '"%s" -h %s -P %s -u %s %s %s < %s 2>&1',
                $mysql,
                escapeshellarg($host),
                escapeshellarg((string)$port),
                escapeshellarg($user),
                $pass !== '' ? '-p' . escapeshellarg($pass) : '',
                escapeshellarg($target),
                escapeshellarg($tmp)
            );
            $out2 = [];
            $code2 = 0;
            \exec($restoreCmd, $out2, $code2);
            @unlink($tmp);
            if ($code2 !== 0) {
                return [
                    'success' => false,
                    'message' => 'Error al cargar el espejo: ' . implode(' ', $out2),
                ];
            }
            return ['success' => true, 'message' => 'ok'];
        }

        return self::cloneViaPhp($source, $target, $cfg);
    }

    /**
     * Fallback sin mysqldump: copia estructura + datos por PDO.
     *
     * @param array<string,mixed> $cfg
     * @return array{success:bool,message:string}
     */
    private static function cloneViaPhp(string $source, string $target, array $cfg): array
    {
        $host = (string)($cfg['host'] ?? 'localhost');
        $port = (int)($cfg['port'] ?? 3306);
        $user = (string)($cfg['username'] ?? '');
        $pass = (string)($cfg['password'] ?? '');
        $charset = (string)($cfg['charset'] ?? 'utf8mb4');

        try {
            $src = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $source, $charset),
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $dst = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $target, $charset),
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Conexión para clonar falló: ' . $e->getMessage()];
        }

        try {
            $dst->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $src->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM);
            foreach ($tables as $row) {
                $table = (string)($row[0] ?? '');
                if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    continue;
                }
                $create = $src->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM);
                $ddl = (string)($create[1] ?? '');
                if ($ddl === '') {
                    continue;
                }
                $dst->exec('DROP TABLE IF EXISTS `' . $table . '`');
                $dst->exec($ddl);
                $dst->exec('INSERT INTO `' . $table . '` SELECT * FROM `' . $source . '`.`' . $table . '`');
            }
            // Vistas (best-effort)
            $views = $src->query('SHOW FULL TABLES WHERE Table_type = \'VIEW\'')->fetchAll(\PDO::FETCH_NUM);
            foreach ($views as $row) {
                $view = (string)($row[0] ?? '');
                if ($view === '' || !preg_match('/^[A-Za-z0-9_]+$/', $view)) {
                    continue;
                }
                try {
                    $create = $src->query('SHOW CREATE VIEW `' . $view . '`')->fetch(\PDO::FETCH_NUM);
                    $ddl = (string)($create[1] ?? '');
                    if ($ddl !== '') {
                        $dst->exec('DROP VIEW IF EXISTS `' . $view . '`');
                        $dst->exec($ddl);
                    }
                } catch (\Throwable $e) {
                    // ignore views
                }
            }
            $dst->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Clonado PHP falló: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function emptyDatabase(string $dbName, \PDO $server): array
    {
        try {
            $server->exec('USE `' . str_replace('`', '``', $dbName) . '`');
            $server->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $server->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM);
            foreach ($tables as $row) {
                $t = (string)($row[0] ?? '');
                if ($t !== '' && preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    $server->exec('DROP TABLE IF EXISTS `' . $t . '`');
                }
            }
            $server->exec('SET FOREIGN_KEY_CHECKS=1');
            return ['success' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'No se pudo vaciar la BD espejo «' . $dbName . '»: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function dropDatabase(string $dbName): array
    {
        $prod = self::productionDbName();
        if ($dbName === '' || strcasecmp($dbName, $prod) === 0) {
            return ['success' => false, 'message' => 'No se puede eliminar la BD de producción'];
        }
        if (!str_ends_with(strtolower($dbName), '_sandbox') && !str_ends_with(strtolower($dbName), '_sb')) {
            $configured = self::sandboxDbName();
            if (strcasecmp($dbName, $configured) !== 0) {
                return ['success' => false, 'message' => 'Nombre de espejo no reconocido'];
            }
        }

        $cfg = \SoftNova\Core\config('database.default', []) ?: [];
        try {
            $server = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=%s',
                    $cfg['host'] ?? 'localhost',
                    (int)($cfg['port'] ?? 3306),
                    $cfg['charset'] ?? 'utf8mb4'
                ),
                (string)($cfg['username'] ?? ''),
                (string)($cfg['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbName) . '`');
            return ['success' => true, 'message' => 'Espejo eliminado'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private static function databaseExists(string $dbName, ?\PDO $server = null): bool
    {
        if ($dbName === '') {
            return false;
        }
        try {
            if ($server === null) {
                $cfg = \SoftNova\Core\config('database.default', []) ?: [];
                $server = new \PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;charset=%s',
                        $cfg['host'] ?? 'localhost',
                        (int)($cfg['port'] ?? 3306),
                        $cfg['charset'] ?? 'utf8mb4'
                    ),
                    (string)($cfg['username'] ?? ''),
                    (string)($cfg['password'] ?? ''),
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            }
            $stmt = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
            $stmt->execute([$dbName]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function writeMarkers(string $sandboxDb, ?\PDO $server = null): void
    {
        $cfg = \SoftNova\Core\config('database.default', []) ?: [];
        try {
            $pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $cfg['host'] ?? 'localhost',
                    (int)($cfg['port'] ?? 3306),
                    $sandboxDb,
                    $cfg['charset'] ?? 'utf8mb4'
                ),
                (string)($cfg['username'] ?? ''),
                (string)($cfg['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            self::upsertSettingOn($pdo, self::MARKER_KEY, '1');
            self::upsertSettingOn($pdo, 'sandbox_cloned_at', date('Y-m-d H:i:s'));
            self::upsertSettingOn($pdo, 'sandbox_source_db', self::productionDbName());
        } catch (\Throwable $e) {
            error_log('SandboxService markers: ' . $e->getMessage());
        }
    }

    private static function readMarkerValue(string $dbName, string $key): ?string
    {
        if ($dbName === '' || !self::databaseExists($dbName)) {
            return null;
        }
        $cfg = \SoftNova\Core\config('database.default', []) ?: [];
        try {
            $pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $cfg['host'] ?? 'localhost',
                    (int)($cfg['port'] ?? 3306),
                    $dbName,
                    $cfg['charset'] ?? 'utf8mb4'
                ),
                (string)($cfg['username'] ?? ''),
                (string)($cfg['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val === false ? null : (string)$val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function upsertSettingOn(\PDO $pdo, string $key, string $value): void
    {
        $exists = $pdo->prepare('SELECT id FROM settings WHERE setting_key = ? LIMIT 1');
        $exists->execute([$key]);
        if ($exists->fetchColumn()) {
            $upd = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            $upd->execute([$value, $key]);
        } else {
            $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
            $ins->execute([$key, $value]);
        }
    }

    private static function findBin(string $name): ?string
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $paths = $isWin
            ? [
                'c:\\xampp\\mysql\\bin\\' . $name . '.exe',
                'C:\\xampp\\mysql\\bin\\' . $name . '.exe',
            ]
            : [
                '/usr/bin/' . $name,
                '/usr/local/bin/' . $name,
                '/usr/local/mysql/bin/' . $name,
            ];

        foreach ($paths as $p) {
            if (\is_file($p)) {
                return $p;
            }
        }

        if (!self::shellExecAvailable()) {
            return null;
        }

        if ($isWin) {
            $out = [];
            \exec('where ' . $name . ' 2>nul', $out);
            if (!empty($out[0]) && \is_file($out[0])) {
                return $out[0];
            }
        } else {
            $out = [];
            \exec('which ' . \escapeshellarg($name) . ' 2>/dev/null', $out);
            if (!empty($out[0]) && \is_file($out[0])) {
                return $out[0];
            }
        }

        return null;
    }
}
