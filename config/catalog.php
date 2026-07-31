<?php

/**
 * Catálogos compartidos: medios de pago, tipos de gasto y canales de producto.
 */
return [
    'payment_methods' => [
        'cash' => 'Efectivo',
        'debit_card' => 'Tarjeta débito',
        'credit_card' => 'Tarjeta crédito',
        'dataphone' => 'Datáfono',
        'payment_link' => 'Link de pago',
        'transfer' => 'Transferencia',
        'card' => 'Tarjeta (genérico)', // legado
        'other' => 'Otro',
    ],
    // Métodos electrónicos → cuenta banco en contabilidad (no afectan caja física)
    'electronic_payment_methods' => [
        'card', 'debit_card', 'credit_card', 'dataphone', 'payment_link', 'transfer',
    ],
    /**
     * Categorías de gasto (código => etiqueta).
     * La agrupación contable está en expense_category_groups.
     */
    'expense_categories' => [
        // Fijos / operativos
        'fixed' => 'Gasto fijo (genérico)',
        'rent' => 'Arriendo',
        'utilities' => 'Servicios públicos',
        'payroll' => 'Nómina / honorarios',
        'marketing' => 'Marketing',
        // Financieros
        'financial' => 'Gasto financiero (genérico)',
        'card_commission' => 'Comisión tarjetas',
        'dataphone_commission' => 'Comisión datáfono',
        'payment_link_commission' => 'Comisión link de pago',
        'bank_fee' => 'Comisión / cuota bancaria',
        'withholding_renta' => 'Retención en la fuente',
        'withholding_ica' => 'Retención ICA',
        'withholding_iva' => 'Retención IVA',
        // Otros
        'sales_commission' => 'Comisión de vendedores',
        'general' => 'General / operativo',
        'other' => 'Otro',
    ],
    /**
     * Agrupa categorías para UI, reportes y contabilidad.
     * fixed → cuenta gastos fijos (510505)
     * financial → cuenta gastos financieros (530505)
     * operating → cuenta general (510505)
     */
    'expense_category_groups' => [
        'fixed' => [
            'label' => 'Gastos fijos',
            'account_setting' => 'fixed_expense_account',
            'default_account' => '510505',
            'categories' => ['fixed', 'rent', 'utilities', 'payroll'],
        ],
        'financial' => [
            'label' => 'Gastos financieros',
            'account_setting' => 'financial_expense_account',
            'default_account' => '530505',
            'categories' => [
                'financial',
                'card_commission',
                'dataphone_commission',
                'payment_link_commission',
                'bank_fee',
                'withholding_renta',
                'withholding_ica',
                'withholding_iva',
            ],
        ],
        'operating' => [
            'label' => 'Gastos operativos / otros',
            'account_setting' => 'general_expense_account',
            'default_account' => '510505',
            'categories' => ['marketing', 'sales_commission', 'general', 'other'],
        ],
    ],
    'product_channels' => [
        'manual' => 'Manual',
        'purchase' => 'Compra a proveedor',
        'woocommerce' => 'WooCommerce',
        'mercadolibre' => 'Mercado Libre',
    ],
];
