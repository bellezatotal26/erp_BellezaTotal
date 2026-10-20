<?php

namespace SoftNova\Services;

/**
 * Presentación y cuadratura del cierre de caja (PDF / correo / WhatsApp).
 */
class CashClosingReportBuilder
{
    /**
     * Compara filas del cierre para explicar por qué “no cuadra” a simple vista.
     *
     * @param array<string,mixed> $totals
     * @param array<string,mixed> $session
     * @return array{
     *   all_ok:bool,
     *   checks:list<array{id:string,label:string,ok:bool,detail:string,gap:float}>,
     *   reconstructed_total:float,
     *   payments_sum:float,
     *   sales_tax_total:float,
     *   sales_delivery_total:float,
     *   other_document_delta:float
     * }
     */
    public static function buildReconciliation(array $totals, array $session = []): array
    {
        $sub = round((float)($totals['sales_subtotal_before_discount'] ?? 0), 2);
        $disc = round((float)($totals['sales_discount_total'] ?? 0), 2);
        $tax = round((float)($totals['sales_tax_total'] ?? 0), 2);
        $del = round((float)($totals['sales_delivery_total'] ?? 0), 2);
        $salesTotal = round((float)($totals['sales_total'] ?? 0), 2);
        $reconstructed = round($sub - $disc + $tax + $del, 2);
        $otherDelta = round($salesTotal - $reconstructed, 2);

        $paymentsSum = 0.0;
        foreach (($totals['payment_breakdown'] ?? []) as $row) {
            $paymentsSum += (float)($row['total'] ?? 0);
        }
        $paymentsSum = round((float)($totals['collected_total'] ?? $paymentsSum), 2);
        $payGap = round($salesTotal - $paymentsSum, 2);

        $expected = (float)($totals['expected_cash'] ?? $session['expected_amount'] ?? 0);
        $counted = $totals['counted_cash'] ?? $session['closing_amount'] ?? null;
        $diff = $totals['difference'] ?? $session['difference_amount'] ?? null;
        if ($diff === null && $counted !== null) {
            $diff = round((float)$counted - $expected, 2);
        }
        $diff = $diff === null ? 0.0 : round((float)$diff, 2);
        $hasCount = $counted !== null && $counted !== '';

        $fmtGap = static function (float $g): string {
            $sign = $g > 0 ? '+' : '';
            return $sign . number_format($g, 2, '.', '');
        };

        $checks = [
            [
                'id' => 'document',
                'label' => 'Documento: subtotal − descuento + IVA + domicilio ≈ total facturado',
                'ok' => abs($otherDelta) < 0.05,
                'detail' => abs($otherDelta) < 0.05
                    ? 'Coincide. Si ve diferencia visual, no reste solo descuento al subtotal: faltan IVA y/o domicilio.'
                    : ('Diferencia ' . $fmtGap($otherDelta) . ' (suele ser recargo de crédito u otros conceptos del total).'),
                'gap' => $otherDelta,
            ],
            [
                'id' => 'payments',
                'label' => 'Cobrado (Σ medios de la sesión) vs facturado del periodo',
                'ok' => abs($payGap) < 0.05,
                'detail' => abs($payGap) < 0.05
                    ? 'Los cobros de la sesión coinciden con el facturado del periodo.'
                    : ('Brecha ' . $fmtGap($payGap)
                        . ' (créditos pendientes, abonos a facturas de otro día o pagos parciales). '
                        . 'Facturado y cobrado son cuentas distintas.'),
                'gap' => $payGap,
            ],
            [
                'id' => 'cash',
                'label' => 'Arqueo de cajón: efectivo contado vs esperado (solo efectivo físico)',
                'ok' => !$hasCount || abs($diff) < 0.05,
                'detail' => !$hasCount
                    ? 'Aún sin conteo (caja abierta). El esperado es la única cifra a confrontar con el cajón.'
                    : (abs($diff) < 0.05
                        ? 'Arqueo cuadrado.'
                        : (($diff < 0 ? 'Faltante ' : 'Sobrante ') . number_format(abs($diff), 2, '.', '') . '.')),
                'gap' => $diff,
            ],
        ];

        // Ajuste ok documento: si otherDelta es grande pero explicable, marcar warning no error duro
        $checks[0]['ok'] = abs($otherDelta) < 0.05;

        $allOk = true;
        foreach ($checks as $c) {
            if (empty($c['ok'])) {
                $allOk = false;
                break;
            }
        }

        return [
            'all_ok' => $allOk,
            'checks' => $checks,
            'reconstructed_total' => $reconstructed,
            'payments_sum' => $paymentsSum,
            'sales_tax_total' => $tax,
            'sales_delivery_total' => $del,
            'other_document_delta' => $otherDelta,
        ];
    }

    /**
     * Cuerpo HTML del correo de cierre (clientes de correo modernos).
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $totals
     * @param callable(float):string $fmt
     */
    public static function buildEmailHtml(
        string $empresa,
        array $session,
        array $totals,
        callable $fmt,
        int $sessionId
    ): string {
        $apertura = !empty($session['opening_date'])
            ? date('d/m/Y H:i', strtotime((string)$session['opening_date']))
            : '—';
        $cierre = !empty($session['closing_date'])
            ? date('d/m/Y H:i', strtotime((string)$session['closing_date']))
            : 'En curso';
        $usuario = htmlspecialchars((string)($session['user_name'] ?? ''));
        $empresaEsc = htmlspecialchars($empresa);
        $summary = self::buildExecutiveSummaryHtml($session, $totals, $fmt, false);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Cierre de caja</title></head>'
            . '<body style="margin:0;padding:0;background:#F1F5F9;font-family:Segoe UI,Arial,sans-serif;color:#0F172A;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9;padding:20px 12px;">'
            . '<tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">'
            . '<tr><td style="padding:8px 4px 16px;">'
            . '<div style="font-size:20px;font-weight:800;color:#0F172A;">Cierre de caja #' . $sessionId . '</div>'
            . '<div style="font-size:13px;color:#64748B;margin-top:4px;">' . $empresaEsc
            . ' · ' . $usuario . ' · ' . htmlspecialchars($apertura) . ' → ' . htmlspecialchars($cierre) . '</div>'
            . '</td></tr>'
            . '<tr><td style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:14px;">'
            . $summary
            . '</td></tr>'
            . '<tr><td style="padding:12px 4px;font-size:12px;color:#64748B;">'
            . 'Adjunto: PDF con el detalle unificado de movimientos y ventas.</td></tr>'
            . '</table></td></tr></table></body></html>';

        return $html;
    }

    /**
     * Resumen ejecutivo unificado (PDF / correo): KPIs + tabla de cuentas en un solo flujo.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $totals
     * @param callable(float):string $fmt
     * @param bool $showCostProfit Incluir costo/utilidad (PDF admin). En pantalla van ocultos con ojo.
     */
    public static function buildExecutiveSummaryHtml(
        array $session,
        array $totals,
        callable $fmt,
        bool $showCostProfit = false
    ): string {
        $opening = (float)($session['opening_amount'] ?? $totals['opening_amount'] ?? 0);
        $salesTotal = (float)($totals['sales_total'] ?? 0);
        $ventasConBase = (float)($totals['ventas_totales_con_base'] ?? ($salesTotal + $opening));
        $cost = (float)($totals['sales_cost_total'] ?? 0);
        $profit = (float)($totals['gross_profit'] ?? ($salesTotal - $cost));
        $margin = (float)($totals['gross_margin_pct'] ?? ($salesTotal > 0.009 ? round(($profit / $salesTotal) * 100, 2) : 0));
        $disc = (float)($totals['sales_discount_total'] ?? 0);
        $cashSales = (float)($totals['cash_sales'] ?? 0);
        $otherCash = (float)($totals['other_cash_incomes'] ?? 0);
        $expenses = (float)($totals['expenses'] ?? 0);
        $expected = (float)($totals['expected_cash'] ?? $session['expected_amount'] ?? 0);
        $counted = (float)($totals['counted_cash'] ?? $session['closing_amount'] ?? 0);
        $diff = (float)($totals['difference'] ?? ($counted - $expected));
        $cancelled = (float)($totals['sales_cancelled_total'] ?? 0);
        $cancelledCount = (int)($totals['sales_cancelled_count'] ?? 0);
        $salesCount = (int)($totals['sales_count'] ?? 0);

        $kpi = static function (string $label, string $value, string $bg, string $extra = ''): string {
            return '<div style="background:' . $bg . ';color:#fff;padding:10px 12px;margin:0 0 6px;border-radius:4px;">'
                . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
                . '<td style="font-weight:700;font-size:13px;letter-spacing:0.02em;">' . $label . $extra . '</td>'
                . '<td style="text-align:right;font-weight:800;font-size:16px;">' . $value . '</td>'
                . '</tr></table></div>';
        };

        $marginBadge = '<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:3px;'
            . 'background:#16A34A;font-size:12px;font-weight:700;">' . number_format($margin, 2, ',', '.') . '%</span>';

        $html = $kpi(
            'FACTURADO + BASE',
            htmlspecialchars($fmt($ventasConBase)),
            '#2563EB',
            '<span style="font-weight:500;font-size:11px;opacity:0.9;"> — ventas del día + apertura ('
            . $salesCount . ' facturada' . ($salesCount === 1 ? '' : 's') . ')</span>'
        );
        if ($showCostProfit) {
            $html .= $kpi('COSTO MERCANCÍA', htmlspecialchars($fmt($cost)), '#DC2626');
            $html .= $kpi('UTILIDAD BRUTA', htmlspecialchars($fmt($profit)), '#EA580C', $marginBadge);
            $html .= '<p style="margin:4px 0 10px;font-size:10px;color:#64748B;line-height:1.4;">'
                . 'Facturado del periodo ' . htmlspecialchars($fmt($salesTotal))
                . ' + base ' . htmlspecialchars($fmt($opening))
                . ' = facturado + base. Utilidad = facturado − costo (sin restar egresos de caja).'
                . ' Σ medios (cobrado) puede diferir del facturado en créditos/abonos.'
                . '</p>';
        } else {
            $html .= '<p style="margin:4px 0 10px;font-size:10px;color:#64748B;line-height:1.4;">'
                . 'Facturado del periodo ' . htmlspecialchars($fmt($salesTotal))
                . ' + base ' . htmlspecialchars($fmt($opening))
                . ' = facturado + base. No confundir con Σ medios cobrados ni con el efectivo del cajón.'
                . '</p>';
        }

        $rows = '';
        $add = static function (
            string $label,
            string $sign,
            string $amountHtml,
            string $bg = '',
            string $amountStyle = ''
        ) use (&$rows): void {
            $rows .= '<tr' . ($bg !== '' ? ' style="background:' . $bg . ';"' : '') . '>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #E5E7EB;font-size:12px;">' . $label . '</td>'
                . '<td style="padding:7px 4px;border-bottom:1px solid #E5E7EB;text-align:center;width:28px;'
                . 'font-weight:700;color:' . ($sign === '+' ? '#059669' : ($sign === '-' ? '#DC2626' : '#64748B')) . ';">'
                . htmlspecialchars($sign) . '</td>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #E5E7EB;text-align:right;font-size:12px;'
                . $amountStyle . '">' . $amountHtml . '</td></tr>';
        };

        $add('Base de apertura <span style="color:#64748B;font-size:10px;">(fondo inicial)</span>', 'i', htmlspecialchars($fmt($opening)), '#FFFBEB');

        if ($cancelled > 0.009 || $cancelledCount > 0) {
            $add(
                'Anulado' . ($cancelledCount > 0 ? ' <span style="color:#64748B;font-size:10px;">(' . $cancelledCount . ')</span>' : ''),
                'i',
                htmlspecialchars($fmt($cancelled))
            );
        }

        foreach (($totals['payment_breakdown'] ?? []) as $code => $row) {
            if ((string)$code === 'cash') {
                continue;
            }
            $label = htmlspecialchars((string)($row['label'] ?? $code));
            $cnt = (int)($row['count'] ?? 0);
            if ($cnt > 0) {
                $label .= ' <span style="color:#64748B;font-size:10px;">(' . $cnt . ')</span>';
            }
            $add($label, 'i', htmlspecialchars($fmt((float)($row['total'] ?? 0))));
        }

        $cashCnt = (int)(($totals['payment_breakdown']['cash']['count'] ?? 0));
        $add(
            'Ventas efectivo' . ($cashCnt > 0 ? ' <span style="color:#64748B;font-size:10px;">(' . $cashCnt . ')</span>' : ''),
            '+',
            htmlspecialchars($fmt($cashSales)),
            '#ECFDF5',
            'color:#059669;font-weight:700;'
        );

        if ($disc > 0.009) {
            $add('Descuentos', 'i', htmlspecialchars($fmt($disc)), '', 'color:#B45309;');
        }

        $refunds = min($otherCash, max(0.0, (float)($totals['purchase_refunds_cash'] ?? 0)));
        $purchases = min($expenses, max(0.0, (float)($totals['purchases_cash'] ?? 0)));
        $ncCashRefunds = min($expenses, max(0.0, (float)($totals['sale_credit_refunds_cash'] ?? 0)));
        $ncDocTotal = max(0.0, (float)($totals['sale_credit_notes_total'] ?? 0));
        $ncDocCount = (int)($totals['sale_credit_notes_count'] ?? 0);
        $ncNumbers = trim((string)($totals['sale_credit_note_numbers'] ?? ''));

        if ($refunds > 0.009) {
            $add(
                'Reintegro compras anuladas <span style="color:#64748B;font-size:10px;">(efectivo vuelve al cajón)</span>',
                '+',
                htmlspecialchars($fmt($refunds)),
                '',
                'color:#059669;'
            );
        }
        if ($otherCash - $refunds > 0.009) {
            $add('Otros ingresos en efectivo', '+', htmlspecialchars($fmt($otherCash - $refunds)), '', 'color:#059669;');
        }

        if ($purchases > 0.009) {
            $add(
                'Compras a proveedores <span style="color:#64748B;font-size:10px;">(OC / abonos en efectivo)</span>',
                '-',
                htmlspecialchars($fmt($purchases)),
                '',
                'color:#DC2626;font-weight:600;'
            );
        }
        if ($ncCashRefunds > 0.009 || $ncDocCount > 0) {
            $ncLabel = 'Reembolsos NC venta'
                . ($ncDocCount > 0 ? ' <span style="color:#64748B;font-size:10px;">(' . $ncDocCount . ')</span>' : '')
                . ' <span style="color:#64748B;font-size:10px;">(efectivo sale del cajón)</span>';
            if ($ncNumbers !== '') {
                $ncLabel .= '<br><span style="color:#64748B;font-size:10px;">' . htmlspecialchars($ncNumbers) . '</span>';
            }
            if ($ncDocTotal > 0.009 && abs($ncDocTotal - $ncCashRefunds) > 0.02) {
                $ncLabel .= '<br><span style="color:#64748B;font-size:10px;">Total NC documental '
                    . htmlspecialchars($fmt($ncDocTotal))
                    . ' (incluye CxC / transferencia)</span>';
            }
            $add(
                $ncLabel,
                '-',
                htmlspecialchars($fmt($ncCashRefunds)),
                '#FFF7ED',
                'color:#C2410C;font-weight:700;'
            );
        }
        $otherExpenses = max(0.0, round($expenses - $purchases - $ncCashRefunds, 2));
        $add('Gastos efectivo (cajón)', '-', htmlspecialchars($fmt($otherExpenses)), '', 'color:#DC2626;font-weight:600;');

        $enCajaHint = $expenses > 0.009
            ? 'Base de caja + efectivo de ventas − '
                . (($purchases > 0.009 || $ncCashRefunds > 0.009) ? 'compras, NC y gastos' : 'gastos')
            : 'Base de caja + efectivo de ventas';
        $rows .= '<tr style="background:#2563EB;">'
            . '<td style="padding:9px 8px;color:#fff;font-weight:700;font-size:13px;">= En caja (esperado)'
            . '<br><span style="font-weight:500;font-size:10px;opacity:0.9;">' . $enCajaHint . '</span></td>'
            . '<td style="padding:9px 4px;text-align:center;color:#fff;font-weight:700;">=</td>'
            . '<td style="padding:9px 8px;text-align:right;color:#fff;font-weight:800;font-size:14px;">'
            . htmlspecialchars($fmt($expected)) . '</td></tr>';

        $add('Dinero contado (cuadre)', 'i', htmlspecialchars($fmt($counted)), '#FEF3C7', 'font-weight:800;');

        $diffOk = abs($diff) < 0.05;
        $diffLabel = $diffOk ? 'Diferencia (cuadrado)' : ($diff < 0 ? 'Diferencia (faltante)' : 'Diferencia (sobrante)');
        $add(
            $diffLabel,
            $diffOk ? '=' : ($diff < 0 ? '-' : '+'),
            ($diff >= 0 ? '+' : '') . htmlspecialchars($fmt($diff)),
            $diffOk ? '#ECFDF5' : '#FEF2F2',
            'font-weight:800;color:' . ($diffOk ? '#059669' : '#DC2626') . ';'
        );

        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #E5E7EB;">'
            . '<tr style="background:#F8FAFC;">'
            . '<th style="text-align:left;padding:8px;font-size:11px;color:#64748B;border-bottom:1px solid #E5E7EB;">Cuenta</th>'
            . '<th style="width:28px;border-bottom:1px solid #E5E7EB;"></th>'
            . '<th style="text-align:right;padding:8px;font-size:11px;color:#64748B;border-bottom:1px solid #E5E7EB;">Monto</th>'
            . '</tr>'
            . $rows
            . '</table>';

        return $html;
    }

    /**
     * Marca en cada venta los cambios de medio de pago (payment_changes) a partir de
     * la auditoría; si no hay auditoría, usa la nota de sale_payments.
     *
     * @param list<array<string,mixed>> $sales
     * @return list<array<string,mixed>>
     */
    public static function attachSaleAdjustments(\PDO $db, array $sales): array
    {
        $ids = [];
        foreach ($sales as $s) {
            if ((int)($s['id'] ?? 0) > 0) {
                $ids[(int)$s['id']] = true;
            }
        }
        if ($ids === []) {
            return $sales;
        }
        $ids = array_keys($ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $changes = [];
        $rows = [];
        foreach ([true, false] as $withMeta) {
            try {
                $st = $db->prepare(
                    "SELECT entity_id, created_at, user_name, description"
                    . ($withMeta ? ', meta_json' : '') . "
                     FROM audit_logs
                     WHERE entity_type = 'sale' AND entity_id IN ($ph)
                       AND module = 'ventas' AND description LIKE 'Medio(s) de pago%'
                     ORDER BY id"
                );
                $st->execute($ids);
                $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                break;
            } catch (\Throwable $e) {
                $rows = [];
            }
        }
        foreach ($rows as $r) {
            $desc = (string)($r['description'] ?? '');
            $meta = json_decode((string)($r['meta_json'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $delta = isset($meta['delta_cash']) ? (float)$meta['delta_cash'] : null;
            if ($delta === null && preg_match('/Δ efectivo\s*(-?[\d.]+)/u', $desc, $dm)) {
                $delta = (float)$dm[1];
            }
            $to = trim((string)($meta['to'] ?? ''));
            if ($to === '' && preg_match('/→\s*(.+?)(?:\s*·\s*Δ.*)?$/u', $desc, $tm)) {
                $to = trim($tm[1]);
            }
            $from = trim((string)($meta['from'] ?? ''));
            $changes[(int)$r['entity_id']][] = [
                'at' => (string)($r['created_at'] ?? ''),
                'user' => (string)($r['user_name'] ?? ''),
                'from' => $from,
                'to' => $to,
                'delta_cash' => $delta,
            ];
        }

        $missing = array_values(array_filter($ids, static fn($id) => !isset($changes[$id])));
        if ($missing !== []) {
            $ph2 = implode(',', array_fill(0, count($missing), '?'));
            try {
                $st = $db->prepare(
                    "SELECT sale_id, MAX(created_at) AS at
                     FROM sale_payments
                     WHERE sale_id IN ($ph2) AND notes LIKE 'Ajuste medio de pago%'
                     GROUP BY sale_id"
                );
                $st->execute($missing);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                    $changes[(int)$r['sale_id']][] = [
                        'at' => (string)($r['at'] ?? ''),
                        'user' => '',
                        'from' => '',
                        'to' => '',
                        'delta_cash' => null,
                    ];
                }
            } catch (\Throwable $e) {
                // Sin columna created_at/notes en instalaciones viejas.
            }
        }

        foreach ($sales as &$s) {
            $list = $changes[(int)($s['id'] ?? 0)] ?? [];
            $prevTo = '';
            $lastIdx = count($list) - 1;
            foreach ($list as $i => &$ch) {
                if ($ch['to'] === '' && $i === $lastIdx) {
                    $ch['to'] = PaymentMethodCatalog::label((string)($s['payment_method'] ?? 'cash'));
                }
                if ($ch['from'] === '') {
                    $ch['from'] = $prevTo !== ''
                        ? $prevTo
                        : self::inferPreviousMethod($ch['to'], $ch['delta_cash'], (float)($s['total'] ?? 0));
                }
                $prevTo = $ch['to'];
            }
            unset($ch);
            $s['payment_changes'] = $list;
        }
        unset($s);

        return $sales;
    }

    /**
     * Medio anterior de cambios registrados antes de guardar "from" en auditoría:
     * solo se puede deducir por el efectivo que entró o salió del cajón.
     */
    private static function inferPreviousMethod(string $to, ?float $deltaCash, float $saleTotal): string
    {
        $cashLabel = PaymentMethodCatalog::label('cash');
        $toIsCash = strcasecmp(trim($to), $cashLabel) === 0;
        $delta = $deltaCash ?? 0.0;
        $isFull = $saleTotal > 0.009 && abs(abs($delta) - $saleTotal) < 0.02;

        if ($delta < -0.009) {
            return $isFull ? $cashLabel : 'Mixto con efectivo';
        }
        if ($delta > 0.009) {
            return $isFull
                ? 'medio electrónico (no quedó registrado cuál)'
                : 'otro medio (no quedó registrado cuál)';
        }
        return $toIsCash
            ? 'no quedó registrado'
            : 'medio electrónico (no quedó registrado cuál)';
    }

    /**
     * Línea de tiempo unificada: ventas + movimientos de caja no ligados a venta.
     *
     * @param list<array<string,mixed>> $sales
     * @param list<array<string,mixed>> $movements
     * @param callable(float):string $fmt
     */
    public static function buildUnifiedActivityHtml(array $sales, array $movements, callable $fmt): string
    {
        $saleLinked = ['sale', 'sale_cancel', 'sale_payment', 'sale_payment_adjust'];
        $rows = [];
        $discountCount = 0;
        $discountSum = 0.0;
        $changeCount = 0;
        $ncCount = 0;
        $ncSum = 0.0;
        $noteStyle = 'display:block;margin-top:2px;font-size:10px;';

        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $notes = '';
            $flags = [];
            $bg = '';

            $disc = round((float)($sale['discount'] ?? 0), 2);
            if ($disc > 0.009) {
                $discountCount++;
                $discountSum += $disc;
                $sub = round((float)($sale['subtotal'] ?? 0), 2);
                $pct = $sub > 0.009 ? ' (' . number_format($disc / $sub * 100, 1, ',', '.') . '%)' : '';
                $notes .= '<span style="' . $noteStyle . 'color:#B45309;">Descuento aplicado: −'
                    . htmlspecialchars($fmt($disc)) . $pct
                    . ($sub > 0.009 ? ' · subtotal ' . htmlspecialchars($fmt($sub)) : '')
                    . '</span>';
                $flags[] = 'Desc.';
                $bg = '#FFFBEB';
            }

            $ncTot = round((float)($sale['credit_note_total'] ?? 0), 2);
            if ($ncTot > 0.009) {
                $ncCount++;
                $ncSum += $ncTot;
                $saleTot = round((float)($sale['total'] ?? 0), 2);
                $ncFull = $ncTot >= ($saleTot - 0.02);
                $ncNums = trim((string)($sale['credit_note_numbers'] ?? ''));
                $notes .= '<span style="' . $noteStyle . 'color:#C2410C;">'
                    . ($ncFull ? 'Devuelta (NC)' : 'Con nota de crédito')
                    . ': −' . htmlspecialchars($fmt($ncTot))
                    . ($ncNums !== '' ? ' · ' . htmlspecialchars($ncNums) : '')
                    . ' · neto ' . htmlspecialchars($fmt(max(0.0, $saleTot - $ncTot)))
                    . '</span>';
                $flags[] = $ncFull ? 'Devuelta' : 'NC';
                $bg = $bg === '' ? '#FFF7ED' : $bg;
            }

            $saleObs = SalesDocumentService::displayNotes((string)($sale['notes'] ?? ''));
            if ($saleObs !== '') {
                $notes .= '<span style="' . $noteStyle . 'color:#475569;">Obs.: '
                    . htmlspecialchars($saleObs) . '</span>';
            }

            foreach (($sale['payment_changes'] ?? []) as $ch) {
                $changeCount++;
                $from = trim((string)($ch['from'] ?? ''));
                $to = trim((string)($ch['to'] ?? ''));
                if ($to === '') {
                    $to = PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'));
                }
                $txt = 'Cambio de medio de pago: antes ' . ($from !== '' ? $from : 'no registrado') . ' → ahora ' . $to;
                $when = [];
                if (!empty($ch['at'])) {
                    $when[] = date('H:i', strtotime((string)$ch['at']) ?: time());
                }
                if (!empty($ch['user'])) {
                    $when[] = (string)$ch['user'];
                }
                if ($when !== []) {
                    $txt .= ' (' . implode(' · ', $when) . ')';
                }
                $delta = $ch['delta_cash'] ?? null;
                if ($delta !== null && abs((float)$delta) > 0.009) {
                    $txt .= (float)$delta < 0
                        ? ' · sale del cajón ' . $fmt(abs((float)$delta))
                        : ' · entra al cajón ' . $fmt((float)$delta);
                }
                $notes .= '<span style="' . $noteStyle . 'color:#1D4ED8;">' . htmlspecialchars($txt) . '</span>';
                $flags[] = 'Cambio pago';
                $bg = $bg === '' ? '#EFF6FF' : $bg;
            }
            $flags = array_values(array_unique($flags));

            $saleUser = trim((string)($sale['user_name'] ?? ''));
            $rows[] = [
                'ts' => (string)($sale['sale_date'] ?? ''),
                'sort' => strtotime((string)($sale['sale_date'] ?? 'now')) ?: 0,
                'tipo' => 'Venta',
                'flags' => $flags,
                'detalle' => trim(
                    (string)($sale['invoice_number'] ?? ('#' . ($sale['id'] ?? '')))
                    . ' · '
                    . (string)($sale['customer_name'] ?? 'Público general')
                    . ($saleUser !== '' ? ' · por ' . $saleUser : '')
                ),
                'notes_html' => $notes,
                'bg' => $bg,
                'medio' => PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash')),
                'amount' => (float)($sale['total'] ?? 0),
                'sign' => '+',
                'color' => '#059669',
            ];
        }

        foreach ($movements as $mov) {
            $rt = (string)($mov['reference_type'] ?? '');
            if (in_array($rt, $saleLinked, true)) {
                continue; // ya aparece como venta
            }
            $isIncome = (($mov['type'] ?? '') === 'income');
            $isNc = $rt === 'sale_credit_note';
            $detalle = trim((string)($mov['origin_label'] ?? ''));
            if ($detalle === '') {
                $detalle = trim((string)($mov['description'] ?? ''));
            }
            if (!empty($mov['user_name'])) {
                $detalle = trim($detalle . ' · ' . $mov['user_name']);
            }
            $rows[] = [
                'ts' => (string)($mov['created_at'] ?? ''),
                'sort' => strtotime((string)($mov['created_at'] ?? 'now')) ?: 0,
                'tipo' => $isNc ? 'NC venta' : ($isIncome ? 'Ingreso' : 'Egreso'),
                'flags' => $isNc ? ['NC'] : [],
                'detalle' => $detalle,
                'bg' => $isNc ? '#FFF7ED' : '',
                'medio' => $isIncome ? 'Caja' : 'Caja',
                'amount' => (float)($mov['amount'] ?? 0),
                'sign' => $isIncome ? '+' : '−',
                'color' => $isNc ? '#C2410C' : ($isIncome ? '#059669' : '#DC2626'),
            ];
        }

        usort($rows, static fn($a, $b) => ($a['sort'] <=> $b['sort']) ?: strcmp($a['detalle'], $b['detalle']));

        if ($rows === []) {
            return '<p style="margin:8px 0;color:#666;font-size:12px;">Sin movimientos ni ventas en esta sesión.</p>';
        }

        $body = '';
        foreach ($rows as $r) {
            $hora = $r['ts'] !== '' ? date('H:i', $r['sort'] ?: time()) : '—';
            $tipo = htmlspecialchars($r['tipo']);
            foreach (($r['flags'] ?? []) as $flag) {
                $isDisc = $flag === 'Desc.';
                $isNcFlag = $flag === 'NC' || $flag === 'Devuelta';
                $tipo .= '<br><span style="display:inline-block;margin-top:2px;padding:1px 4px;border-radius:3px;font-size:9px;font-weight:700;'
                    . ($isDisc
                        ? 'background:#FEF3C7;color:#92400E;'
                        : ($isNcFlag ? 'background:#FFEDD5;color:#9A3412;' : 'background:#DBEAFE;color:#1E40AF;')) . '">'
                    . htmlspecialchars($flag) . '</span>';
            }
            $body .= '<tr' . (!empty($r['bg']) ? ' style="background:' . $r['bg'] . ';"' : '') . '>'
                . '<td style="padding:5px 6px;border-bottom:1px solid #EEE;font-size:11px;">' . htmlspecialchars($hora) . '</td>'
                . '<td style="padding:5px 6px;border-bottom:1px solid #EEE;font-size:11px;">' . $tipo . '</td>'
                . '<td style="padding:5px 6px;border-bottom:1px solid #EEE;font-size:11px;">' . htmlspecialchars($r['detalle'])
                . ($r['notes_html'] ?? '') . '</td>'
                . '<td style="padding:5px 6px;border-bottom:1px solid #EEE;font-size:11px;">' . htmlspecialchars($r['medio']) . '</td>'
                . '<td style="padding:5px 6px;border-bottom:1px solid #EEE;text-align:right;font-size:11px;color:'
                . $r['color'] . ';">' . $r['sign'] . htmlspecialchars($fmt($r['amount'])) . '</td>'
                . '</tr>';
        }

        $summary = '';
        if ($discountCount > 0 || $changeCount > 0 || $ncCount > 0) {
            $parts = [];
            if ($discountCount > 0) {
                $parts[] = '<span style="color:#92400E;"><strong>' . $discountCount . '</strong> venta'
                    . ($discountCount === 1 ? '' : 's') . ' con descuento (' . htmlspecialchars($fmt($discountSum)) . ')</span>';
            }
            if ($changeCount > 0) {
                $parts[] = '<span style="color:#1E40AF;"><strong>' . $changeCount . '</strong> cambio'
                    . ($changeCount === 1 ? '' : 's') . ' de medio de pago</span>';
            }
            if ($ncCount > 0) {
                $parts[] = '<span style="color:#9A3412;"><strong>' . $ncCount . '</strong> venta'
                    . ($ncCount === 1 ? '' : 's') . ' con NC / devolución (' . htmlspecialchars($fmt($ncSum)) . ')</span>';
            }
            $summary = '<p style="margin:0 0 6px;font-size:11px;">' . implode(' &nbsp;·&nbsp; ', $parts) . '</p>';
        }

        return $summary
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
            . '<tr style="background:#F5F5F5;">'
            . '<th style="text-align:left;padding:6px;border-bottom:1px solid #DDD;font-size:11px;">Hora</th>'
            . '<th style="text-align:left;padding:6px;border-bottom:1px solid #DDD;font-size:11px;">Tipo</th>'
            . '<th style="text-align:left;padding:6px;border-bottom:1px solid #DDD;font-size:11px;">Detalle</th>'
            . '<th style="text-align:left;padding:6px;border-bottom:1px solid #DDD;font-size:11px;">Medio</th>'
            . '<th style="text-align:right;padding:6px;border-bottom:1px solid #DDD;font-size:11px;">Monto</th>'
            . '</tr>'
            . $body
            . '</table>';
    }
}
