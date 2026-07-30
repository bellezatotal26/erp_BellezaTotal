<?php

namespace SoftNova\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Servicio de generación de PDF para facturas, cotizaciones y reportes
 */
class PdfService
{
    private Dompdf $dompdf;
    private array $company;
    private array $currency;
    
    public function __construct(array $company = [], array $currency = [])
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);
        
        $this->dompdf = new Dompdf($options);
        $this->company = $company;
        $this->currency = $currency;
    }
    
    /**
     * Generar PDF de factura de venta (carta/A4) o ticket 58mm.
     */
    public function generateInvoice(array $sale, array $items, array $payments = [], string $format = 'a4'): string
    {
        if ($format === 'ticket') {
            return $this->generateTicket58($sale, $items, $payments);
        }

        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $taxName = htmlspecialchars($this->company['tax_name'] ?? 'IVA');
        $docTitle = strtoupper($this->documentLabel((string)($sale['document_type'] ?? 'invoice')));
        $saleTax = (float)($sale['tax'] ?? $sale['tax_amount'] ?? 0);
        $saleSubtotal = (float)($sale['subtotal'] ?? 0);
        $saleTotal = (float)($sale['total'] ?? 0);
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $lineTotal = $this->itemLineTotal($item);
            $itemsHtml .= '<tr>
                <td>' . htmlspecialchars($item['product_name'] ?? '') . '</td>
                <td style="text-align:center;">' . (int)($item['quantity'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['unit_price'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($lineTotal) . '</td>
            </tr>';
        }
        
        $paymentsHtml = '';
        if (!empty($payments)) {
            $paymentsHtml = '<h3 style="margin-top:20px;">Pagos Registrados</h3>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-top:8px;">
                <tr style="background:#f5f5f5;">
                    <th style="border-bottom:1px solid #ddd;text-align:left;">Fecha</th>
                    <th style="border-bottom:1px solid #ddd;text-align:left;">Método</th>
                    <th style="border-bottom:1px solid #ddd;text-align:right;">Monto</th>
                </tr>';
            foreach ($payments as $p) {
                $paymentsHtml .= '<tr>
                    <td>' . date('d/m/Y H:i', strtotime($p['payment_date'])) . '</td>
                    <td>' . htmlspecialchars(PaymentMethodCatalog::label((string)($p['payment_method'] ?? 'cash'))) . '</td>
                    <td style="text-align:right;">' . $fmt($p['amount']) . '</td>
                </tr>';
            }
            $paymentsHtml .= '</table>';
        }
        
        $paidAmount = array_sum(array_map(fn($p) => (float)($p['amount'] ?? 0), $payments));
        $remaining = max(0, $saleTotal - $paidAmount);
        $dueLine = !empty($sale['due_date'])
            ? '<br><strong>Vencimiento:</strong> ' . date('d/m/Y', strtotime((string)$sale['due_date']))
            : '';
        
        $html = $this->wrapHtml($docTitle, $companyName, '
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>No:</strong> ' . htmlspecialchars($sale['invoice_number']) . '<br>
                            <strong>Fecha:</strong> ' . date('d/m/Y H:i', strtotime($sale['sale_date'])) . $dueLine . '<br>
                            <strong>Cliente:</strong> ' . htmlspecialchars($sale['customer_name'] ?? 'Cliente general') . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Método de pago:</strong> ' . htmlspecialchars(PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'))) . '<br>
                            <strong>Estado:</strong> ' . ($sale['payment_status'] === 'paid' ? 'Pagado' : ($sale['payment_status'] === 'partial' ? 'Parcial' : 'Pendiente')) . '
                        </td>
                    </tr>
                </table>
            </div>
            
            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;padding:10px;">Producto</th>
                    <th style="text-align:center;padding:10px;">Cant.</th>
                    <th style="text-align:right;padding:10px;">Precio Unit.</th>
                    <th style="text-align:right;padding:10px;">Subtotal</th>
                </tr>
                ' . $itemsHtml . '
            </table>
            
            <table width="100%" cellpadding="6" cellspacing="0" style="margin-top:15px;">
                <tr>
                    <td width="70%" style="text-align:right;"><strong>Subtotal:</strong></td>
                    <td width="30%" style="text-align:right;">' . $fmt($saleSubtotal) . '</td>
                </tr>
                <tr>
                    <td style="text-align:right;"><strong>' . $taxName . ':</strong></td>
                    <td style="text-align:right;">' . $fmt($saleTax) . '</td>
                </tr>
                <tr style="font-size:16px;font-weight:bold;">
                    <td style="text-align:right;border-top:2px solid #0D7C4A;padding-top:8px;"><strong>TOTAL:</strong></td>
                    <td style="text-align:right;border-top:2px solid #0D7C4A;padding-top:8px;color:#0D7C4A;">' . $fmt($saleTotal) . '</td>
                </tr>
                ' . ($remaining > 0 ? '<tr><td style="text-align:right;color:#DC2626;"><strong>Saldo Pendiente:</strong></td><td style="text-align:right;color:#DC2626;">' . $fmt($remaining) . '</td></tr>' : '') . '
            </table>
            
            ' . $paymentsHtml . '
            
            ' . (!empty($sale['notes']) ? '<p style="margin-top:20px;color:#666;"><strong>Notas:</strong> ' . htmlspecialchars($sale['notes']) . '</p>' : '') . '
        ');
        
        return $this->generate($html, 'A4');
    }

    /** Ticket térmico 58mm (compatible Dompdf, sin CSS flex/@page auto). */
    public function generateTicket58(array $sale, array $items, array $payments = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $fmt = fn($n) => $currency . number_format((float)$n, $decimals, ',', '.');
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $docTitle = strtoupper($this->documentLabel((string)($sale['document_type'] ?? 'invoice')));
        $saleDate = !empty($sale['sale_date']) ? date('d/m/Y H:i', strtotime((string)$sale['sale_date'])) : date('d/m/Y H:i');
        $rows = '';
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['unit_price'] ?? 0);
            $lineTotal = $this->itemLineTotal($item);
            $rows .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0;border-bottom:1px dashed #999;">'
                . '<tr><td colspan="2" style="font-size:10px;">' . htmlspecialchars((string)($item['product_name'] ?? '')) . '</td></tr>'
                . '<tr><td style="font-size:9px;">' . $qty . ' x ' . $fmt($price) . '</td>'
                . '<td style="font-size:10px;text-align:right;"><strong>' . $fmt($lineTotal) . '</strong></td></tr>'
                . '</table>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 4mm; }
            body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 10px; color:#000; }
            h1 { font-size: 12px; margin: 0 0 4px; text-align:center; }
            .muted { color:#444; text-align:center; font-size:9px; margin: 1px 0; }
            .total { font-size: 12px; font-weight:bold; margin-top:8px; border-top:1px solid #000; padding-top:6px; }
        </style></head><body>
            <h1>' . $companyName . '</h1>
            <div class="muted">' . htmlspecialchars($docTitle) . '</div>
            <div class="muted">' . htmlspecialchars((string)($sale['invoice_number'] ?? '')) . '</div>
            <div class="muted">' . htmlspecialchars($saleDate) . '</div>
            <div style="margin:6px 0;">Cliente: ' . htmlspecialchars((string)($sale['customer_name'] ?? 'General')) . '</div>
            ' . $rows . '
            <table width="100%" class="total" cellpadding="0" cellspacing="0"><tr>
                <td>TOTAL</td><td style="text-align:right;">' . $fmt($sale['total'] ?? 0) . '</td>
            </tr></table>
            <div class="muted" style="margin-top:8px;">Gracias por su compra</div>
        </body></html>';
        // ~58mm de ancho en puntos (72pt/inch); alto holgado para Dompdf
        return $this->generate($html, [0.0, 0.0, 164.41, 800.0]);
    }

    private function itemLineTotal(array $item): float
    {
        if (isset($item['subtotal'])) {
            return (float)$item['subtotal'];
        }
        if (isset($item['total'])) {
            return (float)$item['total'];
        }
        return (float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0);
    }

    private function documentLabel(string $type): string
    {
        if (class_exists(SalesDocumentService::class)) {
            try {
                return SalesDocumentService::label($type);
            } catch (\Throwable $e) {
                // fallback abajo
            }
        }
        return match ($type) {
            'remission' => 'Remisión',
            'collection' => 'Cuenta de cobro',
            'electronic' => 'Factura electrónica',
            default => 'Factura',
        };
    }
    
    /**
     * Generar PDF de cotización
     */
    public function generateQuote(array $quote, array $items): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        
        $fmt = fn($n) => $currency . ' ' . number_format($n, $decimals, $decimal, $thousands);
        
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $taxName = htmlspecialchars($this->company['tax_name'] ?? 'IVA');
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<tr>
                <td>' . htmlspecialchars($item['product_name']) . '</td>
                <td style="text-align:center;">' . (int)$item['quantity'] . '</td>
                <td style="text-align:right;">' . $fmt($item['unit_price']) . '</td>
                <td style="text-align:right;">' . $fmt($item['subtotal']) . '</td>
            </tr>';
        }
        
        $validDays = !empty($quote['valid_days']) ? (int)$quote['valid_days'] : 15;
        $validUntil = date('d/m/Y', strtotime('+' . $validDays . ' days', strtotime($quote['created_at'])));
        
        $html = $this->wrapHtml('COTIZACIÓN', $companyName, '
            <div style="margin-bottom:20px;padding:15px;background:#FFF3E0;border-left:4px solid #FF9800;border-radius:4px;">
                <strong>📝 Documento informativo — No es una factura</strong><br>
                <span style="font-size:12px;color:#666;">Válida hasta: ' . $validUntil . ' (' . $validDays . ' días)</span>
            </div>
            
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>Cotización #:</strong> ' . htmlspecialchars($quote['quote_number'] ?? 'N/A') . '<br>
                            <strong>Fecha:</strong> ' . date('d/m/Y', strtotime($quote['created_at'])) . '<br>
                            <strong>Cliente:</strong> ' . htmlspecialchars($quote['customer_name'] ?? 'Cliente general') . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Estado:</strong> ' . ucfirst($quote['status'] ?? 'pending') . '<br>
                            <strong>Válida hasta:</strong> ' . $validUntil . '
                        </td>
                    </tr>
                </table>
            </div>
            
            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:#7C3AED;color:#fff;">
                    <th style="text-align:left;padding:10px;">Producto</th>
                    <th style="text-align:center;padding:10px;">Cant.</th>
                    <th style="text-align:right;padding:10px;">Precio Unit.</th>
                    <th style="text-align:right;padding:10px;">Subtotal</th>
                </tr>
                ' . $itemsHtml . '
            </table>
            
            <table width="100%" cellpadding="6" cellspacing="0" style="margin-top:15px;">
                <tr>
                    <td width="70%" style="text-align:right;"><strong>Subtotal:</strong></td>
                    <td width="30%" style="text-align:right;">' . $fmt($quote['subtotal'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td style="text-align:right;"><strong>' . $taxName . ':</strong></td>
                    <td style="text-align:right;">' . $fmt($quote['tax'] ?? 0) . '</td>
                </tr>
                <tr style="font-size:16px;font-weight:bold;">
                    <td style="text-align:right;border-top:2px solid #7C3AED;padding-top:8px;"><strong>TOTAL:</strong></td>
                    <td style="text-align:right;border-top:2px solid #7C3AED;padding-top:8px;color:#7C3AED;">' . $fmt($quote['total'] ?? 0) . '</td>
                </tr>
            </table>
            
            ' . (!empty($quote['notes']) ? '<p style="margin-top:20px;color:#666;"><strong>Notas:</strong> ' . htmlspecialchars($quote['notes']) . '</p>' : '') . '
        ');
        
        return $this->generate($html);
    }
    
    /**
     * Generar PDF de cierre de caja
     */
    public function generateCashClosing(array $session, array $movements, array $totals): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        
        $fmt = fn($n) => $currency . ' ' . number_format($n, $decimals, $decimal, $thousands);
        
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $diff = ($session['closing_amount'] ?? 0) - ($session['opening_amount'] ?? 0) - ($totals['incomes'] ?? 0) + ($totals['expenses'] ?? 0);
        
        $movementsHtml = '';
        foreach ($movements as $mov) {
            $color = $mov['type'] === 'income' ? '#10B981' : '#DC2626';
            $prefix = $mov['type'] === 'income' ? '+' : '-';
            $movementsHtml .= '<tr>
                <td>' . date('H:i', strtotime($mov['created_at'])) . '</td>
                <td>' . ($mov['type'] === 'income' ? 'Ingreso' : 'Egreso') . '</td>
                <td>' . htmlspecialchars($mov['description']) . '</td>
                <td style="text-align:right;color:' . $color . ';">' . $prefix . $fmt($mov['amount']) . '</td>
            </tr>';
        }
        
        $html = $this->wrapHtml('CIERRE DE CAJA', $companyName, '
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>Caja #:</strong> ' . $session['id'] . '<br>
                            <strong>Apertura:</strong> ' . date('d/m/Y H:i', strtotime($session['opening_date'])) . '<br>
                            <strong>Cierre:</strong> ' . date('d/m/Y H:i', strtotime($session['closing_date'] ?? 'now')) . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Usuario:</strong> ' . htmlspecialchars($session['user_name'] ?? 'N/A') . '
                        </td>
                    </tr>
                </table>
            </div>
            
            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;margin-bottom:20px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;padding:10px;border-bottom:2px solid #ddd;">Concepto</th>
                    <th style="text-align:right;padding:10px;border-bottom:2px solid #ddd;">Monto</th>
                </tr>
                <tr>
                    <td>Monto de Apertura</td>
                    <td style="text-align:right;">' . $fmt($session['opening_amount'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td>Total Ingresos</td>
                    <td style="text-align:right;color:#10B981;">+' . $fmt($totals['incomes'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td>Total Egresos</td>
                    <td style="text-align:right;color:#DC2626;">-' . $fmt($totals['expenses'] ?? 0) . '</td>
                </tr>
                <tr style="font-weight:bold;font-size:14px;">
                    <td style="border-top:2px solid #333;padding-top:8px;">Balance Esperado</td>
                    <td style="text-align:right;border-top:2px solid #333;padding-top:8px;">' . $fmt(($session['opening_amount'] ?? 0) + ($totals['incomes'] ?? 0) - ($totals['expenses'] ?? 0)) . '</td>
                </tr>
                <tr style="font-weight:bold;font-size:14px;">
                    <td style="border-top:2px solid #333;padding-top:8px;">Monto Final Contado</td>
                    <td style="text-align:right;border-top:2px solid #333;padding-top:8px;">' . $fmt($session['closing_amount'] ?? 0) . '</td>
                </tr>
                <tr style="font-weight:bold;font-size:14px;">
                    <td style="border-top:2px solid #333;padding-top:8px;">Diferencia</td>
                    <td style="text-align:right;border-top:2px solid #333;padding-top:8px;color:' . ($diff == 0 ? '#10B981' : '#DC2626') . ';">' . ($diff >= 0 ? '+' : '') . $fmt($diff) . '</td>
                </tr>
            </table>
            
            ' . (!empty($movements) ? '
            <h3 style="margin-top:20px;">Movimientos del Día</h3>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Hora</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Tipo</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Descripción</th>
                    <th style="text-align:right;border-bottom:1px solid #ddd;">Monto</th>
                </tr>
                ' . $movementsHtml . '
            </table>' : '') . '
        ');
        
        return $this->generate($html);
    }
    
    /**
     * Comprobante de pago de nómina (un empleado).
     */
    public function generatePayslip(array $run, array $item, array $employee = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn ($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $name = htmlspecialchars($item['employee_name'] ?? '');
        $doc = htmlspecialchars(trim(($employee['document_type'] ?? '') . ' ' . ($employee['document_number'] ?? '')));

        $rows = [
            ['Salario / días trabajados (' . (int)($item['days_worked'] ?? 0) . ')', $item['salary_base'] ?? 0],
            ['Auxilio de transporte', $item['transport_aid'] ?? 0],
            ['Prima de servicios', $item['prima'] ?? 0],
            ['Cesantías', $item['cesantias'] ?? 0],
            ['Intereses cesantías', $item['cesantias_interest'] ?? 0],
            ['Incapacidad (' . (int)($item['incapacity_days'] ?? 0) . ' días)', $item['incapacity_pay'] ?? 0],
            ['(+) Total devengado', $item['gross_pay'] ?? 0],
            ['(-) Salud empleado', -1 * (float)($item['health_employee'] ?? 0)],
            ['(-) Pensión empleado', -1 * (float)($item['pension_employee'] ?? 0)],
            ['(=) Neto a pagar', $item['net_pay'] ?? 0],
        ];
        $rowsHtml = '';
        foreach ($rows as [$label, $amt]) {
            $bold = str_starts_with($label, '(') || str_starts_with($label, '(=)') || str_starts_with($label, '(+)');
            $rowsHtml .= '<tr' . ($bold ? ' style="font-weight:bold;"' : '') . '>
                <td>' . htmlspecialchars($label) . '</td>
                <td style="text-align:right;">' . $fmt(abs((float)$amt)) . ($amt < 0 ? '' : '') . '</td>
            </tr>';
        }

        $employer = (float)($item['health_employer'] ?? 0) + (float)($item['pension_employer'] ?? 0)
            + (float)($item['arl_employer'] ?? 0) + (float)($item['caja_employer'] ?? 0)
            + (float)($item['sena_employer'] ?? 0) + (float)($item['icbf_employer'] ?? 0);

        $html = $this->wrapHtml('COMPROBANTE DE PAGO DE NÓMINA', $companyName, '
            <p><strong>Liquidación:</strong> ' . htmlspecialchars($run['run_number'] ?? '') . '
               · Periodo ' . htmlspecialchars($run['period_label'] ?? '') . '
               · Pago ' . date('d/m/Y', strtotime($run['pay_date'] ?? 'now')) . '</p>
            <p><strong>Empleado:</strong> ' . $name . ($doc !== '' ? ' · ' . $doc : '') . '</p>
            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;margin-top:12px;">
                <tr style="background:#f5f5f5;"><th style="text-align:left;border-bottom:2px solid #ddd;">Concepto</th>
                <th style="text-align:right;border-bottom:2px solid #ddd;">Valor</th></tr>
                ' . $rowsHtml . '
            </table>
            <p style="margin-top:16px;font-size:11px;color:#666;">Aportes a cargo del empleador (informativo): ' . $fmt($employer) . '
            (salud, pensión, ARL, caja, SENA, ICBF).</p>
            <p style="margin-top:30px;">_______________________________<br>Firma / acuse de recibo</p>
        ');
        return $this->generate($html);
    }

    /**
     * Resumen PDF de toda la liquidación.
     */
    public function generatePayrollRun(array $run, array $items): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn ($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        $bodyRows = '';
        foreach ($items as $it) {
            $bodyRows .= '<tr>
                <td>' . htmlspecialchars($it['employee_name']) . '</td>
                <td style="text-align:center;">' . (int)$it['days_worked'] . '</td>
                <td style="text-align:right;">' . $fmt($it['gross_pay'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt(($it['health_employee'] ?? 0) + ($it['pension_employee'] ?? 0)) . '</td>
                <td style="text-align:right;">' . $fmt($it['net_pay'] ?? 0) . '</td>
            </tr>';
        }

        $html = $this->wrapHtml('LIQUIDACIÓN DE NÓMINA', $companyName, '
            <p><strong>' . htmlspecialchars($run['run_number'] ?? '') . '</strong>
            · Periodo ' . htmlspecialchars($run['period_label'] ?? '') . '
            · Estado ' . htmlspecialchars($run['status'] ?? '') . '</p>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;">Empleado</th><th>Días</th><th style="text-align:right;">Devengado</th>
                    <th style="text-align:right;">Deducciones</th><th style="text-align:right;">Neto</th>
                </tr>
                ' . $bodyRows . '
            </table>
            <table width="100%" cellpadding="6" style="margin-top:16px;">
                <tr><td>Bruto</td><td style="text-align:right;">' . $fmt($run['gross_total'] ?? 0) . '</td></tr>
                <tr><td>Primas</td><td style="text-align:right;">' . $fmt($run['prima_total'] ?? 0) . '</td></tr>
                <tr><td>Cesantías</td><td style="text-align:right;">' . $fmt($run['cesantias_total'] ?? 0) . '</td></tr>
                <tr><td>Incapacidades</td><td style="text-align:right;">' . $fmt($run['incapacity_total'] ?? 0) . '</td></tr>
                <tr><td>Deducciones empleados</td><td style="text-align:right;">' . $fmt($run['deductions_total'] ?? 0) . '</td></tr>
                <tr><td>Aportes empleador</td><td style="text-align:right;">' . $fmt($run['employer_total'] ?? 0) . '</td></tr>
                <tr><td>Parafiscales</td><td style="text-align:right;">' . $fmt($run['parafiscal_total'] ?? 0) . '</td></tr>
                <tr style="font-weight:bold;"><td>Neto a pagar</td><td style="text-align:right;">' . $fmt($run['net_total'] ?? 0) . '</td></tr>
            </table>
        ');
        return $this->generate($html);
    }

    /**
     * Envolver contenido en HTML completo con estilos
     */
        /**
     * Envolver contenido en HTML completo con estilos
     */
    private function wrapHtml(string $title, string $companyName, string $body): string
    {
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . $title . ' - ' . $companyName . '</title>
            <style>
                body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
                .header { text-align: center; margin-bottom: 25px; border-bottom: 3px solid #0D7C4A; padding-bottom: 15px; }
                .header h1 { color: #0D7C4A; margin: 0; font-size: 24px; }
                .header p { color: #666; margin: 5px 0 0 0; font-size: 11px; }
                .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 10px; color: #999; }
                table { width: 100%; }
                @page { margin: 15mm; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>' . $companyName . '</h1>
                <p>' . $title . ' — Generado el ' . date('d/m/Y H:i') . '</p>
            </div>
            ' . $body . '
            <div class="footer">
                <p>Seri ERP &copy; ' . date('Y') . ' — Documento generado electrónicamente</p>
            </div>
        </body>
        </html>';
    }
    
    /**
     * Renderizar HTML a PDF y retornar como string.
     * Crea Dompdf fresco en cada llamada (no reutilizar tras render).
     */
    private function generate(string $html, $paper = 'A4'): string
    {
        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            throw new \RuntimeException('Dompdf no está instalado. Ejecute composer install en el servidor.');
        }
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }
    
    /**
     * Enviar PDF como descarga al navegador
     */
    public function download(string $pdfContent, string $filename): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache');
        echo $pdfContent;
        exit;
    }
    
    /**
     * Mostrar PDF inline en el navegador
     */
    public function inline(string $pdfContent, string $filename): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache');
        echo $pdfContent;
        exit;
    }
}