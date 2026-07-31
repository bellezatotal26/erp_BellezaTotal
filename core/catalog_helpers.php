<?php

namespace SoftNova\Core;

/**
 * Helpers de catálogo (pagos, gastos, canales).
 */
function payment_methods(bool $includeLegacyCard = true): array
{
    $methods = config('payment_methods', []);
    if (!$includeLegacyCard) {
        unset($methods['card']);
    }
    return is_array($methods) ? $methods : [];
}

function payment_method_label(string $code): string
{
    $methods = payment_methods(true);
    return $methods[$code] ?? ucfirst(str_replace('_', ' ', $code));
}

function is_electronic_payment(string $method): bool
{
    $list = config('electronic_payment_methods', []);
    return in_array($method, is_array($list) ? $list : [], true);
}

function expense_categories(): array
{
    $cats = config('expense_categories', []);
    return is_array($cats) ? $cats : [];
}

function expense_category_groups(): array
{
    $groups = config('expense_category_groups', []);
    return is_array($groups) ? $groups : [];
}

function expense_category_label(string $code): string
{
    $cats = expense_categories();
    return $cats[$code] ?? ($code !== '' ? $code : 'General');
}

/**
 * Devuelve el grupo contable de una categoría: fixed | financial | operating
 */
function expense_category_group(string $code): string
{
    foreach (expense_category_groups() as $groupKey => $group) {
        $cats = $group['categories'] ?? [];
        if (in_array($code, is_array($cats) ? $cats : [], true)) {
            return (string)$groupKey;
        }
    }
    // Texto libre antiguo → operativo
    return 'operating';
}

function expense_group_label(string $groupKey): string
{
    $groups = expense_category_groups();
    return (string)($groups[$groupKey]['label'] ?? $groupKey);
}

function product_channels(): array
{
    $ch = config('product_channels', []);
    return is_array($ch) ? $ch : [];
}

function product_channel_label(string $code): string
{
    $ch = product_channels();
    return $ch[$code] ?? ucfirst($code);
}

/**
 * Renderiza <option> de medios de pago.
 */
function payment_method_options(string $selected = 'cash', bool $includeLegacyCard = false): string
{
    $html = '';
    foreach (payment_methods($includeLegacyCard) as $code => $label) {
        if ($code === 'credit') {
            continue;
        }
        $sel = $code === $selected ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
            . htmlspecialchars($label) . '</option>';
    }
    return $html;
}

/**
 * Select de gastos con optgroups (fijos / financieros / operativos).
 */
function expense_category_options(string $selected = 'fixed'): string
{
    $all = expense_categories();
    $groups = expense_category_groups();
    $html = '';
    $used = [];

    foreach ($groups as $group) {
        $label = (string)($group['label'] ?? 'Grupo');
        $html .= '<optgroup label="' . htmlspecialchars($label) . '">';
        foreach (($group['categories'] ?? []) as $code) {
            if (!isset($all[$code])) {
                continue;
            }
            $used[$code] = true;
            $sel = ($code === $selected || strcasecmp((string)$all[$code], $selected) === 0) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
                . htmlspecialchars($all[$code]) . '</option>';
        }
        $html .= '</optgroup>';
    }

    // Categorías huérfanas (compatibilidad)
    $orphan = '';
    foreach ($all as $code => $label) {
        if (isset($used[$code])) {
            continue;
        }
        $sel = ($code === $selected || strcasecmp($label, $selected) === 0) ? ' selected' : '';
        $orphan .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
            . htmlspecialchars($label) . '</option>';
    }
    if ($orphan !== '') {
        $html .= '<optgroup label="Otros">' . $orphan . '</optgroup>';
    }

    return $html;
}
