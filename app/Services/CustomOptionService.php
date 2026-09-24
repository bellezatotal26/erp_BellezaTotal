<?php

namespace SoftNova\Services;

/**
 * Opciones personalizadas de desplegables (motivos, orígenes, medios de pago extra).
 * Las opciones base viven en código; las que el usuario añade con "Otro" quedan en DB.
 */
class CustomOptionService
{
    public const GROUP_CANCEL_REASON = 'sale_cancel_reason';
    public const GROUP_PAYMENT_METHOD = 'payment_method';
    public const GROUP_CUSTOMER_SOURCE = 'customer_source';
    public const GROUP_DISCOUNT_PERCENT = 'discount_percent';
    public const GROUP_PRODUCT_LOCATION = 'product_location';
    public const GROUP_EXPENSE_CATEGORY = 'expense_category';
    public const GROUP_LOAN_REASON = 'loan_reason';
    public const GROUP_LOAN_RECEIVED_REASON = 'loan_received_reason';

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function ensureSchema(): void
    {
        static $done = [];
        $oid = spl_object_id($this->db);
        if (isset($done[$oid])) {
            return;
        }
        $done[$oid] = true;

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS custom_select_options (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                option_group VARCHAR(60) NOT NULL,
                option_value VARCHAR(80) NOT NULL,
                option_label VARCHAR(180) NOT NULL,
                sort_order INT NOT NULL DEFAULT 100,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_custom_option (option_group, option_value),
                KEY idx_custom_group (option_group, status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Motivo de cancelación en ventas (texto libre corto + código).
        try {
            $cols = [];
            foreach ($this->db->query('SHOW COLUMNS FROM sales')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string)$row['Field'])] = true;
            }
            if (!isset($cols['cancel_reason'])) {
                $this->db->exec(
                    "ALTER TABLE sales ADD COLUMN cancel_reason VARCHAR(180) NULL AFTER notes"
                );
            }
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    /** @return list<array{value:string,label:string}> */
    public function defaults(string $group): array
    {
        return match ($group) {
            self::GROUP_CANCEL_REASON => [
                ['value' => 'typing_error', 'label' => 'Error de captura / digitación'],
                ['value' => 'customer_changed_mind', 'label' => 'Cliente desistió'],
                ['value' => 'wrong_product', 'label' => 'Producto equivocado'],
                ['value' => 'return_exchange', 'label' => 'Devolución / cambio'],
                ['value' => 'duplicate', 'label' => 'Venta duplicada'],
                ['value' => 'training', 'label' => 'Prueba / capacitación'],
            ],
            self::GROUP_CUSTOMER_SOURCE => [
                ['value' => 'Facebook', 'label' => 'Facebook'],
                ['value' => 'Instagram', 'label' => 'Instagram'],
                ['value' => 'WhatsApp', 'label' => 'WhatsApp'],
                ['value' => 'Google', 'label' => 'Google'],
                ['value' => 'Recomendación', 'label' => 'Recomendación'],
                ['value' => 'Tienda Física', 'label' => 'Tienda Física'],
                ['value' => 'Llamada', 'label' => 'Llamada'],
                ['value' => 'Email', 'label' => 'Email'],
                ['value' => 'Feria/Evento', 'label' => 'Feria/Evento'],
            ],
            self::GROUP_PAYMENT_METHOD => [],
            self::GROUP_DISCOUNT_PERCENT => [],
            self::GROUP_PRODUCT_LOCATION => [
                ['value' => 'Bodega', 'label' => 'Bodega'],
                ['value' => 'Almacén 1', 'label' => 'Almacén 1'],
                ['value' => 'Local 2', 'label' => 'Local 2'],
            ],
            self::GROUP_LOAN_REASON => (static function (): array {
                $out = [];
                foreach (ProductLoan::reasons() as $value => $label) {
                    if ($value === ProductLoan::REASON_OTRO) {
                        continue;
                    }
                    $out[] = ['value' => $value, 'label' => $label];
                }
                return $out;
            })(),
            self::GROUP_LOAN_RECEIVED_REASON => (static function (): array {
                $out = [];
                foreach (ProductLoan::receivedReasons() as $value => $label) {
                    if ($value === 'otro') {
                        continue;
                    }
                    $out[] = ['value' => $value, 'label' => $label];
                }
                return $out;
            })(),
            default => [],
        };
    }

    /** @return list<array{value:string,label:string}> */
    public function custom(string $group): array
    {
        try {
            $rows = $this->query(
                "SELECT option_value AS value, option_label AS label
                 FROM custom_select_options
                 WHERE option_group = ? AND status = 'active'
                 ORDER BY sort_order ASC, id ASC",
                [$group]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static function (array $r): array {
            return [
                'value' => (string)$r['value'],
                'label' => (string)$r['label'],
            ];
        }, $rows);
    }

    /**
     * Defaults + personalizadas, sin duplicar por value.
     * @return list<array{value:string,label:string}>
     */
    public function options(string $group, bool $includeOther = true): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge($this->defaults($group), $this->custom($group)) as $opt) {
            $value = (string)$opt['value'];
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $opt;
        }
        if ($includeOther) {
            $out[] = ['value' => '__other__', 'label' => 'Otro'];
        }
        return $out;
    }

    /** HTML de <option>…</option> */
    public function optionsHtml(string $group, string $selected = '', bool $includeOther = true): string
    {
        $html = '';
        foreach ($this->options($group, $includeOther) as $opt) {
            $sel = ((string)$opt['value'] === $selected) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars((string)$opt['value']) . '"' . $sel . '>'
                . htmlspecialchars((string)$opt['label']) . '</option>';
        }
        return $html;
    }

    public function label(string $group, string $value): string
    {
        foreach ($this->options($group, false) as $opt) {
            if ((string)$opt['value'] === $value) {
                return (string)$opt['label'];
            }
        }
        return $value;
    }

    /**
     * Añade una opción permanente. Si ya existe (mismo value o label), la reutiliza.
     * @return array{value:string,label:string,created:bool}
     */
    public function add(string $group, string $label, array $extra = []): array
    {
        $group = trim($group);
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
        $allowed = [
            self::GROUP_CANCEL_REASON,
            self::GROUP_PAYMENT_METHOD,
            self::GROUP_CUSTOMER_SOURCE,
            self::GROUP_DISCOUNT_PERCENT,
            self::GROUP_PRODUCT_LOCATION,
            self::GROUP_EXPENSE_CATEGORY,
            self::GROUP_LOAN_REASON,
            self::GROUP_LOAN_RECEIVED_REASON,
        ];
        if (!in_array($group, $allowed, true)) {
            throw new \InvalidArgumentException('Grupo de opciones no permitido');
        }
        if ($group === self::GROUP_LOAN_REASON) {
            throw new \InvalidArgumentException(
                'Los motivos de préstamo (salida) son fijos (proveedor, muestra u otro). '
                . 'Use «Otro» y detalle en la nota; no se agregan motivos al listado.'
            );
        }
        if ($label === '') {
            throw new \InvalidArgumentException('Escriba una opción válida');
        }
        if ($group !== self::GROUP_DISCOUNT_PERCENT && mb_strlen($label) < 2) {
            throw new \InvalidArgumentException('Escriba una opción válida (mínimo 2 caracteres)');
        }
        if (mb_strlen($label) > 120) {
            $label = mb_substr($label, 0, 120);
        }

        // Categorías de gasto: se crean en expense_categories (valor = id).
        if ($group === self::GROUP_EXPENSE_CATEGORY) {
            $kind = (string)($extra['kind'] ?? 'operational');
            if (!ExpenseCategoryService::isValidKind($kind)) {
                $kind = 'operational';
            }
            $svc = new ExpenseCategoryService($this->db);
            $cat = $svc->addOrReuse($label, $kind);
            // Asegurar grupo elegido en el modal (operativo vs administrativo).
            if (($cat['kind'] ?? '') !== $kind) {
                $svc->setKind((int)$cat['id'], $kind);
                $cat['kind'] = $kind;
            }
            return [
                'value' => (string)$cat['id'],
                'label' => (string)$cat['name'],
                'created' => (bool)$cat['created'],
                'kind' => (string)$cat['kind'],
            ];
        }

        if ($group === self::GROUP_DISCOUNT_PERCENT) {
            $pct = (float)str_replace(',', '.', preg_replace('/[^\d.,]/', '', $label) ?? '0');
            if ($pct <= 0 || $pct > 100) {
                throw new \InvalidArgumentException('El descuento debe estar entre 0.01 y 100');
            }
            $pct = round($pct, 2);
            $label = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
            $value = (string)$pct;
            foreach ($this->options($group, false) as $opt) {
                if ((string)$opt['value'] === $value || mb_strtolower((string)$opt['label']) === mb_strtolower($label)) {
                    return ['value' => (string)$opt['value'], 'label' => (string)$opt['label'], 'created' => false];
                }
            }
        } else {
            // Reutilizar por etiqueta (case-insensitive).
            foreach ($this->options($group, false) as $opt) {
                if (mb_strtolower((string)$opt['label']) === mb_strtolower($label)) {
                    return ['value' => (string)$opt['value'], 'label' => (string)$opt['label'], 'created' => false];
                }
            }
            // Origen de cliente / ubicación: se guarda el texto legible.
            $value = in_array($group, [self::GROUP_CUSTOMER_SOURCE, self::GROUP_PRODUCT_LOCATION], true)
                ? $label
                : $this->slug($label, $group);
        }
        $existing = $this->query(
            "SELECT option_value, option_label, status FROM custom_select_options
             WHERE option_group = ? AND option_value = ? LIMIT 1",
            [$group, $value]
        )->fetch();
        if ($existing) {
            if (($existing['status'] ?? '') !== 'active') {
                $this->query(
                    "UPDATE custom_select_options SET status = 'active', option_label = ? WHERE option_group = ? AND option_value = ?",
                    [$label, $group, $value]
                );
            }
            return [
                'value' => (string)$existing['option_value'],
                'label' => $label,
                'created' => false,
            ];
        }

        $this->query(
            "INSERT INTO custom_select_options (option_group, option_value, option_label, sort_order)
             VALUES (?, ?, ?, 100)",
            [$group, $value, $label]
        );

        return ['value' => $value, 'label' => $label, 'created' => true];
    }

    private function slug(string $label, string $group): string
    {
        $base = mb_strtolower(trim($label));
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'custom';
        }
        if ($group === self::GROUP_PAYMENT_METHOD) {
            $base = 'pm_' . $base;
        } elseif ($group === self::GROUP_CANCEL_REASON) {
            $base = 'cr_' . $base;
        } elseif ($group === self::GROUP_LOAN_RECEIVED_REASON) {
            $base = 'lrr_' . $base;
        }
        $base = substr($base, 0, 60);

        $candidate = $base;
        $i = 2;
        while ($this->valueExists($group, $candidate)) {
            $candidate = substr($base, 0, 55) . '_' . $i;
            $i++;
            if ($i > 50) {
                $candidate = substr($base, 0, 40) . '_' . bin2hex(random_bytes(2));
                break;
            }
        }
        return $candidate;
    }

    private function valueExists(string $group, string $value): bool
    {
        foreach ($this->defaults($group) as $opt) {
            if ((string)$opt['value'] === $value) {
                return true;
            }
        }
        $n = (int)$this->query(
            "SELECT COUNT(*) FROM custom_select_options WHERE option_group = ? AND option_value = ?",
            [$group, $value]
        )->fetchColumn();
        return $n > 0;
    }
}
