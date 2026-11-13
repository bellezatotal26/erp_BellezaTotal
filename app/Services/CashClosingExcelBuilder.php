<?php

declare(strict_types=1);

namespace SoftNova\Services;

/**
 * Excel del cierre de caja: 3 hojas (Resumen+medios, Ventas+movimientos, Por usuario).
 */
final class CashClosingExcelBuilder
{
    /**
     * @param array{
     *   session:array<string,mixed>,
     *   movements:list<array<string,mixed>>,
     *   totals:array<string,mixed>,
     *   sales:list<array<string,mixed>>,
     *   admin_expenses?:list<array<string,mixed>>
     * } $report
     * @return list<array{name:string,headers:list<string>,rows:list<list<mixed>>,columnTypes:array<int,string>}>
     */
    public static function buildSheets(array $report, ?\PDO $db = null): array
    {
        $session = $report['session'] ?? [];
        $totals = $report['totals'] ?? [];
        $sales = $report['sales'] ?? [];
        $movements = $report['movements'] ?? [];

        return [
            self::sheetResumenYMedios($session, $totals),
            self::sheetVentasYMovimientos($sales, $movements, $db),
            self::sheetPorUsuario($movements),
        ];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $totals
     * @return array{name:string,headers:list<string>,rows:list<list<mixed>>,columnTypes:array<int,string>}
     */
    private static function sheetResumenYMedios(array $session, array $totals): array
    {
        $opening = round((float)($session['opening_amount'] ?? 0), 2);
        $cashSales = round((float)($totals['cash_sales'] ?? 0), 2);
        $otherCash = round((float)($totals['other_cash_incomes'] ?? 0), 2);
        $expenses = round((float)($totals['expenses'] ?? 0), 2);
        $expected = round((float)($totals['expected_cash'] ?? $session['expected_amount'] ?? 0), 2);
        $counted = round((float)($totals['counted_cash'] ?? $session['closing_amount'] ?? 0), 2);
        $diff = round((float)($totals['difference'] ?? ($counted - $expected)), 2);
        $salesTotal = round((float)($totals['sales_total'] ?? 0), 2);
        $collected = round((float)($totals['collected_total'] ?? 0), 2);
        $closingObs = CashClosingReportBuilder::closingObservation($session);

        $money = static fn(float $n): array => ['v' => $n, 't' => 'currency'];

        $rows = [
            ['Sesión', 'Caja #', (string)(int)($session['id'] ?? 0), '', ''],
            ['Sesión', 'Quién abrió', (string)($session['user_name'] ?? ''), '', ''],
            ['Sesión', 'Apertura', self::fmtDt($session['opening_date'] ?? null), '', ''],
            ['Sesión', 'Cierre', self::fmtDt($session['closing_date'] ?? null), '', ''],
            ['Sesión', 'Observación de cierre', $closingObs !== '' ? $closingObs : '—', '', ''],
            ['', '', '', '', ''],
            ['Cajón', 'Base cajón (apertura)', '', '', $money($opening)],
            ['Cajón', 'Efectivo cobrado (ventas + abonos)', '', '', $money($cashSales)],
            ['Cajón', 'Otros ingresos efectivo', '', '', $money($otherCash)],
            ['Cajón', 'Egresos del cajón', '', '', $money($expenses)],
            ['Cajón', 'Efectivo esperado en cajón', '', '', $money($expected)],
            ['Cajón', 'Dinero contado (cuadre)', '', '', $money($counted)],
            ['Cajón', 'Diferencia (contado − esperado)', '', '', $money($diff)],
            ['Cajón', 'Neto cajón (esperado − base)', '', '', $money(round($expected - $opening, 2))],
            ['', '', '', '', ''],
            ['Ventas', 'Ventas facturadas (cantidad)', '', (int)($totals['sales_count'] ?? 0), ''],
            ['Ventas', 'Ventas facturadas (total)', '', '', $money($salesTotal)],
            ['Ventas', 'Sub-total sin descuentos', '', '', $money(round((float)($totals['sales_subtotal_before_discount'] ?? 0), 2))],
            ['Ventas', 'Descuento total aplicado', '', '', $money(round((float)($totals['sales_discount_total'] ?? 0), 2))],
            ['Ventas', 'Cobrado Σ medios', '', '', $money($collected)],
            ['Ventas', 'Otros medios (no efectivo)', '', '', $money(round((float)($totals['other_incomes'] ?? 0), 2))],
            ['Ventas', 'NC / devoluciones (cantidad)', '', (int)($totals['sale_credit_notes_count'] ?? 0), ''],
            ['Ventas', 'NC / devoluciones (total doc.)', '', '', $money(round((float)($totals['sale_credit_notes_total'] ?? 0), 2))],
            ['Ventas', 'NC reembolso efectivo', '', '', $money(round((float)($totals['sale_credit_refunds_cash'] ?? 0), 2))],
            ['', '', '', '', ''],
            ['Medios de pago', 'Medio', 'Nota', 'Cant. cobros', 'Total'],
        ];

        $break = $totals['payment_breakdown'] ?? [];
        if (is_array($break) && $break !== []) {
            uasort($break, static function ($a, $b) {
                return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
            });
            foreach ($break as $code => $row) {
                $codeStr = (string)$code;
                $isCash = $codeStr === 'cash';
                $isCredit = $codeStr === 'credit' || !empty($row['informational']);
                $note = $isCash ? 'Entra al cajón' : ($isCredit ? 'Por cobrar (informativo)' : 'No entra al cajón');
                $rows[] = [
                    'Medios de pago',
                    (string)($row['label'] ?? $codeStr),
                    $note,
                    (int)($row['count'] ?? 0),
                    $money(round((float)($row['total'] ?? 0), 2)),
                ];
            }
        } else {
            $rows[] = ['Medios de pago', 'Sin cobros registrados', '', '', ''];
        }

        return [
            'name' => 'Resumen',
            'headers' => ['Sección', 'Concepto', 'Detalle', 'Cantidad', 'Monto'],
            'rows' => $rows,
            'columnTypes' => [3 => 'number', 4 => 'currency'],
        ];
    }

    /**
     * Ventas del día + movimientos de cajón/abonos en una sola línea de tiempo.
     *
     * @param list<array<string,mixed>> $sales
     * @param list<array<string,mixed>> $movements
     * @return array{name:string,headers:list<string>,rows:list<list<mixed>>,columnTypes:array<int,string>}
     */
    private static function sheetVentasYMovimientos(array $sales, array $movements, ?\PDO $db): array
    {
        $rows = [];
        $seq = 0;

        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $sid = (int)($sale['id'] ?? 0);
            $pm = strtolower(trim((string)($sale['payment_method'] ?? '')));
            $payStatus = strtolower(trim((string)($sale['payment_status'] ?? '')));
            $isCredit = ($pm === 'credit') || in_array($payStatus, ['pending', 'partial'], true);
            $isMixed = !$isCredit && CashClosingReportBuilder::saleHasMixedBreakdown($sale);
            $methodLabel = PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'), $db);
            $parts = '';
            if ($isMixed) {
                $parts = CashClosingReportBuilder::formatPaymentPartsLabel(
                    is_array($sale['payments'] ?? null) ? $sale['payments'] : [],
                    null,
                    $db
                );
                $methodLabel = 'Pago mixto';
            } elseif ($isCredit) {
                $methodLabel = 'Crédito';
            }

            $tipo = $isCredit ? 'Venta crédito' : ($isMixed ? 'Venta mixta' : 'Venta');
            $discAmt = round((float)($sale['discount'] ?? 0), 2);
            $moneyFmt = static fn(float $n): string => '$ ' . number_format($n, 2, ',', '.');
            $discDetail = CashClosingReportBuilder::formatSaleDiscountDetail($sale, $moneyFmt);
            $itemCount = is_array($sale['items'] ?? null) ? count($sale['items']) : 0;
            $notaParts = [];
            if ($discDetail !== '') {
                $notaParts[] = $discDetail;
            }
            if ($itemCount > 0) {
                $notaParts[] = $itemCount . ($itemCount === 1 ? ' producto' : ' productos');
            }
            $notaParts[] = $isCredit ? 'Informativo' : 'Facturado';
            $saleSort = strtotime((string)($sale['sale_date'] ?? '')) ?: 0;
            $rows[] = [
                'sort' => $saleSort,
                'seq' => $seq++,
                'cells' => [
                    $tipo,
                    self::fmtDt($sale['sale_date'] ?? null),
                    (string)($sale['user_name'] ?? ''),
                    (string)($sale['invoice_number'] ?? ('#' . $sid)),
                    (string)($sale['customer_name'] ?? 'Público general'),
                    $methodLabel,
                    $parts !== '' ? $parts : ($isCredit ? 'Por cobrar' : '—'),
                    $discAmt > 0.009
                        ? ['v' => $discAmt, 't' => 'currency']
                        : '—',
                    ['v' => round((float)($sale['total'] ?? 0), 2), 't' => 'currency'],
                    implode(' · ', $notaParts),
                ],
            ];

            // Filas hijas: un producto por línea bajo la venta.
            $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
            $itemIdx = 0;
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $itemIdx++;
                $qty = (float)($it['quantity'] ?? 0);
                $qtyStr = abs($qty - round($qty)) < 0.001
                    ? (string)(int)round($qty)
                    : number_format($qty, 2, ',', '.');
                $name = trim((string)($it['product_name'] ?? 'Producto'));
                $code = trim((string)($it['product_code'] ?? ''));
                $label = $code !== '' ? ($code . ' · ' . $name) : $name;
                $unit = round((float)($it['unit_price'] ?? 0), 2);
                $lineTot = round((float)($it['line_total'] ?? 0), 2);
                $rows[] = [
                    // Mantener juntos bajo la venta (mismo sort + seq).
                    'sort' => $saleSort,
                    'seq' => $seq++,
                    'cells' => [
                        '  · Producto',
                        self::fmtDt($sale['sale_date'] ?? null),
                        (string)($sale['user_name'] ?? ''),
                        (string)($sale['invoice_number'] ?? ('#' . $sid)) . ' · ' . $label,
                        $qtyStr . '× @ ' . number_format($unit, 2, ',', '.'),
                        '—',
                        'Ítem ' . $itemIdx,
                        '—',
                        ['v' => $lineTot, 't' => 'currency'],
                        'Desglose venta',
                    ],
                ];
            }
        }

        foreach ($movements as $m) {
            $isIn = (string)($m['type'] ?? '') === 'income';
            $isMeta = !empty($m['_meta_only']) || (int)($m['id'] ?? 0) <= 0;
            $rt = (string)($m['reference_type'] ?? '');
            $rid = (int)($m['reference_id'] ?? 0);
            // Cobro efectivo de una venta ya listada: no duplicar (queda en fila Venta / mixta).
            if (in_array($rt, ['sale', 'sale_cancel', 'sale_payment_adjust'], true) && $rid > 0) {
                $skip = false;
                foreach ($sales as $sale) {
                    if ((int)($sale['id'] ?? 0) !== $rid) {
                        continue;
                    }
                    $pm = strtolower(trim((string)($sale['payment_method'] ?? '')));
                    $st = strtolower(trim((string)($sale['payment_status'] ?? '')));
                    $isCreditSale = ($pm === 'credit') || in_array($st, ['pending', 'partial'], true);
                    if (!$isCreditSale) {
                        $skip = true;
                    }
                    break;
                }
                if ($skip) {
                    continue;
                }
            }

            $method = (string)($m['payment_method'] ?? '');
            $methodLabel = $method !== ''
                ? PaymentMethodCatalog::label($method, $db)
                : ($rt === 'sale_payment' ? 'Caja' : '—');
            $tipo = $rt === 'sale_payment'
                ? 'Abono'
                : ($rt === 'sale_credit_note' ? 'NC venta' : ($isIn ? 'Ingreso' : 'Egreso'));
            if ($isMeta) {
                $tipo .= ' (info)';
            }
            $desc = (string)(($m['origin_label'] ?? '') !== '' ? $m['origin_label'] : ($m['description'] ?? ''));
            $amt = round((float)($m['amount'] ?? 0), 2);
            $rows[] = [
                'sort' => strtotime((string)($m['created_at'] ?? '')) ?: 0,
                'seq' => $seq++,
                'cells' => [
                    $tipo,
                    self::fmtDt($m['created_at'] ?? null),
                    (string)($m['user_name'] ?? ''),
                    $desc,
                    '—',
                    $methodLabel,
                    $rt !== '' ? $rt : '—',
                    '—',
                    ['v' => $isIn ? $amt : -$amt, 't' => 'currency'],
                    $isMeta ? 'Informativo' : 'Cajón',
                ],
            ];
        }

        usort($rows, static function ($a, $b) {
            return ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0))
                ?: ((int)($a['seq'] ?? 0) <=> (int)($b['seq'] ?? 0));
        });

        $out = [];
        foreach ($rows as $r) {
            $out[] = $r['cells'];
        }

        return [
            'name' => 'Ventas y movimientos',
            'headers' => [
                'Tipo', 'Fecha', 'Usuario', 'Factura / descripción', 'Cliente',
                'Medio', 'Desglose / ref.', 'Descuento', 'Monto', 'Nota',
            ],
            'rows' => $out,
            'columnTypes' => [7 => 'currency', 8 => 'currency'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $movements
     * @return array{name:string,headers:list<string>,rows:list<list<mixed>>,columnTypes:array<int,string>}
     */
    private static function sheetPorUsuario(array $movements): array
    {
        $byUser = [];
        foreach ($movements as $m) {
            $uName = trim((string)($m['user_name'] ?? 'Sin usuario'));
            if ($uName === '') {
                $uName = 'Sin usuario';
            }
            if (!isset($byUser[$uName])) {
                $byUser[$uName] = [
                    'incomes' => 0.0,
                    'expenses' => 0.0,
                    'count' => 0,
                    'abonos_count' => 0,
                    'abonos_total' => 0.0,
                    'abonos_cash' => 0.0,
                    'abonos_other' => 0.0,
                ];
            }
            $isIn = (string)($m['type'] ?? '') === 'income';
            $amt = round((float)($m['amount'] ?? 0), 2);
            $isMeta = !empty($m['_meta_only']) || (int)($m['id'] ?? 0) <= 0;
            $isAbono = (string)($m['reference_type'] ?? '') === 'sale_payment';
            $method = (string)($m['payment_method'] ?? '');

            if ($isAbono && $isIn) {
                $byUser[$uName]['abonos_count']++;
                $byUser[$uName]['abonos_total'] = round($byUser[$uName]['abonos_total'] + $amt, 2);
                if (PaymentMethodCatalog::affectsCash($method !== '' ? $method : 'cash') && !$isMeta) {
                    $byUser[$uName]['abonos_cash'] = round($byUser[$uName]['abonos_cash'] + $amt, 2);
                } else {
                    $byUser[$uName]['abonos_other'] = round($byUser[$uName]['abonos_other'] + $amt, 2);
                }
            }
            if ($isMeta) {
                continue;
            }
            $byUser[$uName][$isIn ? 'incomes' : 'expenses'] = round(
                $byUser[$uName][$isIn ? 'incomes' : 'expenses'] + $amt,
                2
            );
            $byUser[$uName]['count']++;
        }

        $rows = [];
        foreach ($byUser as $name => $bu) {
            if ((int)$bu['count'] === 0 && (int)$bu['abonos_count'] === 0) {
                continue;
            }
            $rows[] = [
                $name,
                (int)$bu['count'],
                ['v' => $bu['incomes'], 't' => 'currency'],
                ['v' => $bu['expenses'], 't' => 'currency'],
                ['v' => round($bu['incomes'] - $bu['expenses'], 2), 't' => 'currency'],
                (int)$bu['abonos_count'],
                ['v' => $bu['abonos_total'], 't' => 'currency'],
                ['v' => $bu['abonos_cash'], 't' => 'currency'],
                ['v' => $bu['abonos_other'], 't' => 'currency'],
            ];
        }

        return [
            'name' => 'Por usuario',
            'headers' => [
                'Usuario', 'Mov. cajón', 'Ingresos cajón', 'Egresos cajón', 'Neto cajón',
                'Abonos #', 'Abonos total', 'Abonos efectivo', 'Abonos otros medios',
            ],
            'rows' => $rows,
            'columnTypes' => [
                1 => 'number', 2 => 'currency', 3 => 'currency', 4 => 'currency',
                5 => 'number', 6 => 'currency', 7 => 'currency', 8 => 'currency',
            ],
        ];
    }

    private static function fmtDt(mixed $value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '—';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return date('d/m/Y H:i', $ts);
    }
}
