<?php

namespace SoftNova\Services;

/**
 * Motivos tipificados para salida de inventario por préstamo (no es venta).
 * La devolución / reposición del préstamo es manual (no se cubre al comprar).
 *
 * Códigos oficiales de salida (orden fijo, con trazabilidad en product_stock_debts.reason
 * y en notas del movimiento de inventario):
 * - prestamo_proveedor
 * - muestra
 * - otro (+ nota libre)
 */
class ProductLoan
{
    /** @deprecated Solo historial */
    public const REASON_EMPLEADO = 'prestamo_empleado';
    /** @deprecated Solo historial */
    public const REASON_CLIENTE = 'prestamo_cliente';
    public const REASON_PROVEEDOR = 'prestamo_proveedor';
    public const REASON_MUESTRA = 'muestra';
    public const REASON_OTRO = 'otro';

    public const SOURCE = 'loan';
    /** Nos prestaron mercancía (entrada); pendiente de devolver al prestamista. */
    public const SOURCE_RECEIVED = 'loan_received';

    public const OWNERSHIP_BORROWED = 'borrowed';

    /**
     * Motivos activos de salida (selector).
     *
     * @return array<string,string>
     */
    public static function reasons(): array
    {
        return [
            self::REASON_PROVEEDOR => 'Préstamo a proveedor',
            self::REASON_MUESTRA => 'Muestra / demostración',
            self::REASON_OTRO => 'Otro',
        ];
    }

    /**
     * Motivos retirados del selector; se conservan para etiquetar historial/trazabilidad.
     *
     * @return array<string,string>
     */
    public static function legacyReasons(): array
    {
        return [
            self::REASON_EMPLEADO => 'Préstamo a empleado',
            self::REASON_CLIENTE => 'Préstamo a cliente',
        ];
    }

    /** Motivos base al recibir un préstamo (alguien nos presta). */
    public static function receivedReasons(): array
    {
        return [
            'prestamo_empresa' => 'Préstamo de empresa / aliado',
            'muestra_recibida' => 'Muestra recibida',
            'otro' => 'Otro',
        ];
    }

    /** @return array<string,string> */
    public static function legacyReceivedReasons(): array
    {
        return [
            'prestamo_persona' => 'Préstamo de persona',
        ];
    }

    /**
     * Alinea el catálogo en BD: deja activos solo los motivos oficiales de salida
     * y desactiva opciones viejas/personalizadas que ensuciaban el selector.
     */
    public static function syncCatalog(?\PDO $db): void
    {
        if (!$db instanceof \PDO) {
            return;
        }
        try {
            $allowed = array_keys(self::reasons());
            $allowed = array_values(array_filter(
                $allowed,
                static fn(string $v): bool => $v !== self::REASON_OTRO
            ));
            // Desactivar todo lo que no sea motivo oficial (empleado, cliente, lr_*, etc.).
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $params = array_merge([CustomOptionService::GROUP_LOAN_REASON], $allowed);
            $db->prepare(
                "UPDATE custom_select_options
                 SET status = 'inactive'
                 WHERE option_group = ?
                   AND status = 'active'
                   AND option_value NOT IN ({$placeholders})"
            )->execute($params);

            // Asegurar filas oficiales activas (relación estable en BD).
            $sort = 10;
            foreach (self::reasons() as $value => $label) {
                if ($value === self::REASON_OTRO) {
                    continue;
                }
                $exists = $db->prepare(
                    "SELECT id, status FROM custom_select_options
                     WHERE option_group = ? AND option_value = ? LIMIT 1"
                );
                $exists->execute([CustomOptionService::GROUP_LOAN_REASON, $value]);
                $row = $exists->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $db->prepare(
                        "UPDATE custom_select_options
                         SET option_label = ?, status = 'active', sort_order = ?
                         WHERE id = ?"
                    )->execute([$label, $sort, (int)$row['id']]);
                } else {
                    $db->prepare(
                        "INSERT INTO custom_select_options
                            (option_group, option_value, option_label, status, sort_order)
                         VALUES (?, ?, ?, 'active', ?)"
                    )->execute([CustomOptionService::GROUP_LOAN_REASON, $value, $label, $sort]);
                }
                $sort += 10;
            }
        } catch (\Throwable $e) {
            // Tabla aún no migrada
        }
    }

    /**
     * Catálogo «Nos prestan»: oficiales + personalizados en BD.
     * Desactiva solo el motivo retirado «préstamo de persona».
     */
    public static function syncReceivedCatalog(?\PDO $db): void
    {
        if (!$db instanceof \PDO) {
            return;
        }
        try {
            // Retirado del selector (sigue legible en historial).
            $db->prepare(
                "UPDATE custom_select_options
                 SET status = 'inactive'
                 WHERE option_group = ? AND option_value = ? AND status = 'active'"
            )->execute([CustomOptionService::GROUP_LOAN_RECEIVED_REASON, 'prestamo_persona']);

            $sort = 10;
            foreach (self::receivedReasons() as $value => $label) {
                if ($value === 'otro') {
                    continue;
                }
                $exists = $db->prepare(
                    "SELECT id FROM custom_select_options
                     WHERE option_group = ? AND option_value = ? LIMIT 1"
                );
                $exists->execute([CustomOptionService::GROUP_LOAN_RECEIVED_REASON, $value]);
                $row = $exists->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $db->prepare(
                        "UPDATE custom_select_options
                         SET option_label = ?, status = 'active', sort_order = ?
                         WHERE id = ?"
                    )->execute([$label, $sort, (int)$row['id']]);
                } else {
                    $db->prepare(
                        "INSERT INTO custom_select_options
                            (option_group, option_value, option_label, status, sort_order)
                         VALUES (?, ?, ?, 'active', ?)"
                    )->execute([CustomOptionService::GROUP_LOAN_RECEIVED_REASON, $value, $label, $sort]);
                }
                $sort += 10;
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @return array{reason:string,note:string,lender:string}
     */
    public static function parseReceived(?string $reason, ?string $note = null, ?string $lender = null, ?\PDO $db = null): array
    {
        $reason = strtolower(trim((string)$reason));
        if ($reason === '' || $reason === '__other__' || $reason === 'other') {
            throw new \InvalidArgumentException('Seleccione el motivo del préstamo recibido');
        }
        if ($reason === 'prestamo_persona') {
            throw new \InvalidArgumentException(
                'El motivo «Préstamo de persona» ya no está disponible. Elija otro o cree uno con «Otro».'
            );
        }
        if (!self::isValidReceived($reason, $db)) {
            throw new \InvalidArgumentException('Seleccione el motivo del préstamo recibido');
        }
        $note = trim(preg_replace('/\s+/', ' ', (string)$note) ?? '');
        if (mb_strlen($note) > 180) {
            $note = mb_substr($note, 0, 180);
        }
        $lender = trim(preg_replace('/\s+/', ' ', (string)$lender) ?? '');
        if (mb_strlen($lender) > 120) {
            $lender = mb_substr($lender, 0, 120);
        }
        if ($lender === '') {
            throw new \InvalidArgumentException('Indique quién nos presta (persona o empresa)');
        }
        if ($reason === 'otro' && mb_strlen($note) < 3) {
            throw new \InvalidArgumentException('Detalle el motivo "Otro"');
        }
        return ['reason' => $reason, 'note' => $note, 'lender' => $lender];
    }

    public static function isValidReceived(string $reason, ?\PDO $db = null): bool
    {
        $reason = strtolower(trim($reason));
        if ($reason === '' || $reason === '__other__' || $reason === 'other' || $reason === 'prestamo_persona') {
            return false;
        }
        if (isset(self::receivedReasons()[$reason])) {
            return true;
        }
        if ($db instanceof \PDO) {
            try {
                foreach ((new CustomOptionService($db))->options(CustomOptionService::GROUP_LOAN_RECEIVED_REASON, false) as $opt) {
                    if ((string)$opt['value'] === $reason) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return false;
    }

    public static function receivedLabel(string $reason, ?\PDO $db = null): string
    {
        $reason = strtolower(trim($reason));
        $map = self::receivedReasons() + self::legacyReceivedReasons();
        if (isset($map[$reason])) {
            return $map[$reason];
        }
        if ($db instanceof \PDO) {
            try {
                $stmt = $db->prepare(
                    "SELECT option_label FROM custom_select_options
                     WHERE option_group = ? AND option_value = ?
                     LIMIT 1"
                );
                $stmt->execute([CustomOptionService::GROUP_LOAN_RECEIVED_REASON, $reason]);
                $lbl = trim((string)$stmt->fetchColumn());
                if ($lbl !== '') {
                    return $lbl;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return self::label($reason, $db);
    }

    public static function label(string $reason, ?\PDO $db = null): string
    {
        $reason = strtolower(trim($reason));
        if ($reason === self::REASON_OTRO || $reason === 'otro') {
            return 'Otro';
        }
        $map = self::reasons() + self::legacyReasons() + self::receivedReasons() + self::legacyReceivedReasons();
        if (isset($map[$reason])) {
            return $map[$reason];
        }
        if ($db instanceof \PDO) {
            try {
                foreach ([
                    CustomOptionService::GROUP_LOAN_RECEIVED_REASON,
                    CustomOptionService::GROUP_LOAN_REASON,
                ] as $group) {
                    $stmt = $db->prepare(
                        "SELECT option_label FROM custom_select_options
                         WHERE option_group = ? AND option_value = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$group, $reason]);
                    $lbl = trim((string)$stmt->fetchColumn());
                    if ($lbl !== '') {
                        return $lbl;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Compatibilidad con deudas de sobreventa
        return StockOversell::label($reason);
    }

    public static function isValid(string $reason, ?\PDO $db = null): bool
    {
        $reason = strtolower(trim($reason));
        if ($reason === '' || $reason === '__other__' || $reason === 'other') {
            return false;
        }
        // Solo motivos oficiales de salida (nuevos registros).
        return isset(self::reasons()[$reason]);
    }

    /**
     * @return array{reason:string,note:string,borrower:string}
     */
    public static function parse(?string $reason, ?string $note = null, ?string $borrower = null, ?\PDO $db = null): array
    {
        $reason = strtolower(trim((string)$reason));
        if ($reason === '__other__' || $reason === 'other') {
            $reason = self::REASON_OTRO;
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('Seleccione el motivo del préstamo');
        }
        if (!self::isValid($reason, $db)) {
            throw new \InvalidArgumentException(
                'Motivo no válido. Use: Préstamo a proveedor, Muestra / demostración u Otro.'
            );
        }
        $note = trim(preg_replace('/\s+/', ' ', (string)$note) ?? '');
        if (mb_strlen($note) > 180) {
            $note = mb_substr($note, 0, 180);
        }
        $borrower = trim(preg_replace('/\s+/', ' ', (string)$borrower) ?? '');
        if (mb_strlen($borrower) > 120) {
            $borrower = mb_substr($borrower, 0, 120);
        }
        if ($reason === self::REASON_OTRO && $note === '') {
            throw new \InvalidArgumentException('Indique el detalle del motivo "Otro"');
        }
        if ($borrower === '' && $note === '') {
            throw new \InvalidArgumentException('Indique a quién se presta o una nota con el motivo');
        }
        return ['reason' => $reason, 'note' => $note, 'borrower' => $borrower];
    }

    public static function optionsHtml(?string $selected = null, ?\PDO $db = null): string
    {
        if ($db instanceof \PDO) {
            self::syncCatalog($db);
        }

        $html = '<option value="">Seleccione motivo…</option>';
        foreach (self::reasons() as $value => $label) {
            if ($value === self::REASON_OTRO) {
                continue;
            }
            $sel = $selected === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        // Historial: si el registro abierto tiene un motivo legado/custom, mostrarlo seleccionado.
        if (
            $selected !== null
            && $selected !== ''
            && $selected !== self::REASON_OTRO
            && $selected !== '__other__'
            && !isset(self::reasons()[$selected])
        ) {
            $html .= '<option value="' . htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') . '" selected>'
                . htmlspecialchars(self::label($selected, $db), ENT_QUOTES, 'UTF-8')
                . ' (histórico)</option>';
        }

        $selOther = ($selected === self::REASON_OTRO || $selected === '__other__') ? ' selected' : '';
        $html .= '<option value="' . self::REASON_OTRO . '"' . $selOther . '>Otro</option>';
        return $html;
    }

    public static function receivedOptionsHtml(?string $selected = null, ?\PDO $db = null): string
    {
        if ($db instanceof \PDO) {
            self::syncReceivedCatalog($db);
            $html = '<option value="">Seleccione motivo…</option>';
            $html .= (new CustomOptionService($db))->optionsHtml(
                CustomOptionService::GROUP_LOAN_RECEIVED_REASON,
                (string)($selected ?? ''),
                true
            );
            // Historial: motivo retirado «persona» u otro inactivo.
            if (
                $selected !== null
                && $selected !== ''
                && $selected !== '__other__'
                && $selected !== 'otro'
                && !self::isValidReceived($selected, $db)
            ) {
                $html .= '<option value="' . htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') . '" selected>'
                    . htmlspecialchars(self::receivedLabel($selected, $db), ENT_QUOTES, 'UTF-8')
                    . ' (histórico)</option>';
            }
            return $html;
        }

        $html = '<option value="">Seleccione motivo…</option>';
        foreach (self::receivedReasons() as $value => $label) {
            if ($value === 'otro') {
                continue;
            }
            $sel = $selected === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '<option value="__other__">Otro</option>';
        return $html;
    }
}
