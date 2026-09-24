<?php

namespace SoftNova\Services;

/**
 * Configuración de impresión de documentos de venta (PDF carta y ticket 58mm).
 * Se guarda en settings.sales_document_config como JSON.
 */
class SalesDocumentConfig
{
    public const SETTING_KEY = 'sales_document_config';

    /** @return array{a4:array<string,mixed>,ticket:array<string,mixed>} */
    public static function defaults(): array
    {
        return [
            'a4' => [
                'show_logo' => true,
                'show_company_name' => true,
                'show_nit' => true,
                'show_address' => true,
                'show_phone' => true,
                'show_email' => true,
                'show_customer' => true,
                'show_due_date' => true,
                'show_payment_method' => true,
                'show_payment_status' => true,
                'show_unit_price' => true,
                'show_subtotal_tax' => true,
                'show_payments_table' => true,
                'show_notes' => true,
                'show_balance_due' => true,
                'show_system_footer' => true,
                'footer_text' => '',
                'header_extra' => '',
                'header_color' => '#0D7C4A',
            ],
            'ticket' => [
                'show_logo' => true,
                'show_company_name' => true,
                'show_nit' => true,
                'show_address' => true,
                'show_phone' => true,
                'show_email' => false,
                'show_customer' => true,
                'show_due_date' => false,
                'show_payment_method' => true,
                'show_payment_status' => false,
                'show_unit_price' => true,
                'show_subtotal_tax' => true,
                'show_payments_table' => false,
                'show_notes' => false,
                'show_balance_due' => false,
                'show_system_footer' => false,
                'footer_text' => 'Gracias por su compra',
                'header_extra' => '',
                'header_color' => '#000000',
                'auto_print_ticket' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed>|string|null $raw
     * @return array{a4:array<string,mixed>,ticket:array<string,mixed>}
     */
    public static function normalize(array|string|null $raw): array
    {
        $defaults = self::defaults();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $out = [];
        foreach (['a4', 'ticket'] as $format) {
            $src = is_array($raw[$format] ?? null) ? $raw[$format] : [];
            $cfg = $defaults[$format];
            foreach ($cfg as $key => $defaultVal) {
                if (!array_key_exists($key, $src)) {
                    continue;
                }
                if (is_bool($defaultVal)) {
                    $cfg[$key] = filter_var($src[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$src[$key];
                } elseif ($key === 'header_color') {
                    $color = strtoupper(trim((string)$src[$key]));
                    $cfg[$key] = preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : $defaultVal;
                } else {
                    $cfg[$key] = trim((string)$src[$key]);
                    if (mb_strlen($cfg[$key]) > 500) {
                        $cfg[$key] = mb_substr($cfg[$key], 0, 500);
                    }
                }
            }
            $out[$format] = $cfg;
        }

        return $out;
    }

    /**
     * Lee desde settings (mapa clave→valor o valor JSON crudo).
     *
     * @param array<string,mixed>|string|null $settingsOrJson
     * @return array{a4:array<string,mixed>,ticket:array<string,mixed>}
     */
    public static function fromSettings(array|string|null $settingsOrJson): array
    {
        if (is_array($settingsOrJson) && !isset($settingsOrJson['a4']) && !isset($settingsOrJson['ticket'])) {
            return self::normalize($settingsOrJson[self::SETTING_KEY] ?? null);
        }
        return self::normalize($settingsOrJson);
    }

    /**
     * @param array<string,mixed> $posted Campos del formulario (doc_a4_*, doc_ticket_*)
     * @return array{a4:array<string,mixed>,ticket:array<string,mixed>}
     */
    public static function fromRequest(array $posted): array
    {
        $defaults = self::defaults();
        $built = ['a4' => [], 'ticket' => []];
        foreach (['a4', 'ticket'] as $format) {
            $prefix = 'doc_' . $format . '_';
            foreach ($defaults[$format] as $key => $defaultVal) {
                $field = $prefix . $key;
                if (is_bool($defaultVal)) {
                    // Checkbox: ausente = false
                    $built[$format][$key] = !empty($posted[$field]);
                } else {
                    $built[$format][$key] = array_key_exists($field, $posted)
                        ? (string)$posted[$field]
                        : $defaultVal;
                }
            }
        }
        return self::normalize($built);
    }

    /** Etiquetas UI para toggles. */
    public static function toggleLabels(): array
    {
        return [
            'show_logo' => 'Logo',
            'show_company_name' => 'Nombre de empresa',
            'show_nit' => 'NIT / Documento',
            'show_address' => 'Dirección',
            'show_phone' => 'Teléfono',
            'show_email' => 'Correo',
            'show_customer' => 'Cliente',
            'show_due_date' => 'Fecha de vencimiento',
            'show_payment_method' => 'Método de pago',
            'show_payment_status' => 'Estado de pago',
            'show_unit_price' => 'Precio unitario en ítems',
            'show_subtotal_tax' => 'Subtotal e impuesto',
            'show_payments_table' => 'Tabla de pagos',
            'show_notes' => 'Notas de la venta',
            'show_balance_due' => 'Saldo pendiente',
            'show_system_footer' => 'Pie “Seri ERP”',
        ];
    }
}
