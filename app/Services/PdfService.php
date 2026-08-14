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
     * Respeta sales_document_config (Configuración → Documentos).
     */
    public function generateInvoice(array $sale, array $items, array $payments = [], string $format = 'a4'): string
    {
        if ($format === 'ticket') {
            return $this->generateTicket58($sale, $items, $payments);
        }

        $cfg = SalesDocumentConfig::fromSettings($this->company)['a4'];
        $color = htmlspecialchars((string)($cfg['header_color'] ?? '#0D7C4A'));
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);

        $companyName = htmlspecialchars((string)($this->company['company_name'] ?? 'Mi Empresa'));
        $taxName = htmlspecialchars((string)($this->company['tax_name'] ?? 'IVA'));
        $docTitle = strtoupper($this->documentLabel((string)($sale['document_type'] ?? 'invoice')));
        $saleTax = (float)($sale['tax'] ?? $sale['tax_amount'] ?? 0);
        $saleSubtotal = (float)($sale['subtotal'] ?? 0);
        $saleTotal = (float)($sale['total'] ?? 0);

        $itemsHtml = '';
        foreach ($items as $item) {
            $lineTotal = $this->itemLineTotal($item);
            $itemsHtml .= '<tr><td>' . htmlspecialchars((string)($item['product_name'] ?? '')) . '</td>'
                . '<td style="text-align:center;">' . (int)($item['quantity'] ?? 0) . '</td>';
            if (!empty($cfg['show_unit_price'])) {
                $itemsHtml .= '<td style="text-align:right;">' . $fmt($item['unit_price'] ?? 0) . '</td>';
            }
            $itemsHtml .= '<td style="text-align:right;">' . $fmt($lineTotal) . '</td></tr>';
        }

        $paymentsHtml = '';
        if (!empty($cfg['show_payments_table']) && !empty($payments)) {
            $paymentsHtml = '<h3 style="margin-top:20px;">Pagos Registrados</h3>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-top:8px;">
                <tr style="background:#f5f5f5;">
                    <th style="border-bottom:1px solid #ddd;text-align:left;">Fecha</th>
                    <th style="border-bottom:1px solid #ddd;text-align:left;">Método</th>
                    <th style="border-bottom:1px solid #ddd;text-align:right;">Monto</th>
                </tr>';
            foreach ($payments as $p) {
                $paymentsHtml .= '<tr>
                    <td>' . date('d/m/Y H:i', strtotime((string)$p['payment_date'])) . '</td>
                    <td>' . htmlspecialchars(PaymentMethodCatalog::label((string)($p['payment_method'] ?? 'cash'))) . '</td>
                    <td style="text-align:right;">' . $fmt($p['amount'] ?? 0) . '</td>
                </tr>';
            }
            $paymentsHtml .= '</table>';
        }

        $paidAmount = array_sum(array_map(fn($p) => (float)($p['amount'] ?? 0), $payments));
        $remaining = max(0, $saleTotal - $paidAmount);
        $dueLine = (!empty($cfg['show_due_date']) && !empty($sale['due_date']))
            ? '<br><strong>Vencimiento:</strong> ' . date('d/m/Y', strtotime((string)$sale['due_date']))
            : '';

        $leftMeta = '<strong>No:</strong> ' . htmlspecialchars((string)($sale['invoice_number'] ?? '')) . '<br>'
            . '<strong>Fecha:</strong> ' . date('d/m/Y H:i', strtotime((string)($sale['sale_date'] ?? 'now'))) . $dueLine;
        if (!empty($cfg['show_customer'])) {
            $leftMeta .= '<br><strong>Cliente:</strong> ' . htmlspecialchars((string)($sale['customer_name'] ?? 'Cliente Final'));
        }
        $rightMeta = '';
        if (!empty($cfg['show_payment_method'])) {
            $rightMeta .= '<strong>Método de pago:</strong> ' . htmlspecialchars(PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'))) . '<br>';
        }
        if (!empty($cfg['show_payment_status'])) {
            $statusLabel = ($sale['payment_status'] ?? '') === 'paid' ? 'Pagado'
                : (($sale['payment_status'] ?? '') === 'partial' ? 'Parcial' : 'Pendiente');
            $rightMeta .= '<strong>Estado:</strong> ' . $statusLabel;
        }

        $totalsHtml = '';
        if (!empty($cfg['show_subtotal_tax'])) {
            $totalsHtml .= '<tr><td width="70%" style="text-align:right;"><strong>Subtotal:</strong></td>'
                . '<td width="30%" style="text-align:right;">' . $fmt($saleSubtotal) . '</td></tr>'
                . '<tr><td style="text-align:right;"><strong>' . $taxName . ':</strong></td>'
                . '<td style="text-align:right;">' . $fmt($saleTax) . '</td></tr>';
        }
        $deliveryClient = max(0.0, (float)($sale['delivery_fee'] ?? 0));
        if ($deliveryClient > 0.009) {
            $totalsHtml .= '<tr><td style="text-align:right;"><strong>Domicilio (cliente):</strong></td>'
                . '<td style="text-align:right;">' . $fmt($deliveryClient) . '</td></tr>';
        }
        $totalsHtml .= '<tr style="font-size:16px;font-weight:bold;">
                <td style="text-align:right;border-top:2px solid ' . $color . ';padding-top:8px;"><strong>TOTAL:</strong></td>
                <td style="text-align:right;border-top:2px solid ' . $color . ';padding-top:8px;color:' . $color . ';">' . $fmt($saleTotal) . '</td>
            </tr>';
        $cashInfo = $this->saleCashInfo($sale, $saleTotal);
        if ($cashInfo['received'] > 0.009) {
            $totalsHtml .= '<tr><td style="text-align:right;"><strong>Billete / recibido:</strong></td>'
                . '<td style="text-align:right;">' . $fmt($cashInfo['received']) . '</td></tr>';
            $totalsHtml .= '<tr><td style="text-align:right;"><strong>Vueltas / cambio:</strong></td>'
                . '<td style="text-align:right;">' . $fmt($cashInfo['change']) . '</td></tr>';
        }
        if (!empty($cfg['show_balance_due']) && $remaining > 0.009) {
            $totalsHtml .= '<tr><td style="text-align:right;color:#DC2626;"><strong>Saldo Pendiente:</strong></td>'
                . '<td style="text-align:right;color:#DC2626;">' . $fmt($remaining) . '</td></tr>';
        }

        $notesHtml = '';
        if (!empty($cfg['show_notes']) && !empty($sale['notes'])) {
            $notesHtml = '<p style="margin-top:20px;color:#666;"><strong>Notas:</strong> ' . htmlspecialchars((string)$sale['notes']) . '</p>';
        }
        $customFooter = trim((string)($cfg['footer_text'] ?? ''));
        $customFooterHtml = $customFooter !== ''
            ? '<p style="margin-top:16px;text-align:center;color:#555;">' . nl2br(htmlspecialchars($customFooter)) . '</p>'
            : '';
        $systemFooter = !empty($cfg['show_system_footer'])
            ? '<div class="footer"><p>Seri ERP &copy; ' . date('Y') . ' — Documento generado electrónicamente</p></div>'
            : '';

        $itemHead = '<th style="text-align:left;padding:10px;">Producto</th>'
            . '<th style="text-align:center;padding:10px;">Cant.</th>'
            . (!empty($cfg['show_unit_price']) ? '<th style="text-align:right;padding:10px;">Precio Unit.</th>' : '')
            . '<th style="text-align:right;padding:10px;">Subtotal</th>';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
            <title>' . $docTitle . ' - ' . $companyName . '</title>
            <style>
                body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
                .header { text-align: center; margin-bottom: 25px; border-bottom: 3px solid ' . $color . '; padding-bottom: 15px; }
                .header h1 { color: ' . $color . '; margin: 0; font-size: 22px; }
                .header .meta { color: #666; margin: 4px 0 0 0; font-size: 11px; }
                .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 10px; color: #999; }
                table { width: 100%; }
                @page { margin: 15mm; }
            </style></head><body>
            <div class="header">
                ' . $this->companyLogoHtml(!empty($cfg['show_logo']), '140px', '70px') . '
                ' . (!empty($cfg['show_company_name']) ? '<h1>' . $companyName . '</h1>' : '') . '
                <div class="meta">' . $docTitle . '</div>
                ' . $this->companyContactLinesHtml($cfg, 'meta') . '
                ' . $this->headerExtraHtml($cfg) . '
            </div>
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0"><tr>
                    <td width="55%">' . $leftMeta . '</td>
                    <td width="45%" style="text-align:right;">' . $rightMeta . '</td>
                </tr></table>
            </div>
            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:' . $color . ';color:#fff;">' . $itemHead . '</tr>
                ' . $itemsHtml . '
            </table>
            <table width="100%" cellpadding="6" cellspacing="0" style="margin-top:15px;">' . $totalsHtml . '</table>
            ' . $paymentsHtml . '
            ' . $notesHtml . '
            ' . $customFooterHtml . '
            ' . $systemFooter . '
        </body></html>';

        return $this->generate($html, 'A4');
    }

    /** Ticket térmico 58mm estilo POS (logo, cliente, tabla DESC/CANT/VALOR, pagos sin duplicar). */
    public function generateTicket58(array $sale, array $items, array $payments = []): string
    {
        $cfg = SalesDocumentConfig::fromSettings($this->company)['ticket'];
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $fmt = fn($n) => $currency . number_format((float)$n, $decimals, ',', '.');
        $esc = static fn($s) => htmlspecialchars(self::utf8Clean((string)$s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $companyName = $esc($this->company['company_name'] ?? 'Mi Empresa');
        $taxName = $esc($this->company['tax_name'] ?? 'IVA');
        $invoice = $esc($sale['invoice_number'] ?? ('VTA-' . ($sale['id'] ?? '')));
        $saleTs = !empty($sale['sale_date']) ? strtotime((string)$sale['sale_date']) : time();
        $saleDate = date('Y-m-d', $saleTs);
        $saleTime = date('H:i:s', $saleTs);
        $cashier = $esc($sale['user_name'] ?? $sale['cashier_name'] ?? '');
        $saleTax = (float)($sale['tax'] ?? $sale['tax_amount'] ?? 0);
        $saleSubtotal = (float)($sale['subtotal'] ?? 0);
        $saleTotal = (float)($sale['total'] ?? 0);
        if ($saleSubtotal <= 0 && $saleTotal > 0) {
            $saleSubtotal = max(0, $saleTotal - $saleTax);
        }

        $items = $this->uniqueSaleItems($items);

        $header = '';
        if (!empty($cfg['show_logo'])) {
            $header .= $this->companyLogoHtml(true, '110px', '55px');
        }
        if (!empty($cfg['show_company_name'])) {
            $header .= '<div class="co">' . $companyName . '</div>';
        }
        $contactBits = [];
        if (!empty($cfg['show_address'])) {
            $addr = trim((string)($this->company['company_address'] ?? ''));
            if ($addr !== '') {
                $contactBits[] = $esc($addr);
            }
        }
        if (!empty($cfg['show_phone'])) {
            $phone = trim((string)($this->company['company_phone'] ?? ''));
            if ($phone !== '') {
                $contactBits[] = 'Tel: ' . $esc($phone);
            }
        }
        if (!empty($cfg['show_nit'])) {
            $nit = trim((string)($this->company['company_nit'] ?? ''));
            if ($nit !== '') {
                $contactBits[] = 'NIT: ' . $esc($nit);
            }
        }
        if (!empty($cfg['show_email'])) {
            $email = trim((string)($this->company['company_email'] ?? ''));
            if ($email !== '') {
                $contactBits[] = $esc($email);
            }
        }
        if ($contactBits !== []) {
            $header .= '<div class="muted">' . implode('<br>', $contactBits) . '</div>';
        }
        $header .= $this->headerExtraHtml($cfg, 'muted');
        $header .= '<div class="dash"></div>';

        $meta = '<div class="inv">' . $invoice . '</div>'
            . '<div class="muted">' . $esc($saleDate) . ' | ' . $esc($saleTime) . '</div>';
        if ($cashier !== '') {
            $meta .= '<div class="muted">Cajero: ' . $cashier . '</div>';
        }
        if (!empty($cfg['show_due_date']) && !empty($sale['due_date'])) {
            $meta .= '<div class="muted">Vence: ' . $esc(date('Y-m-d', strtotime((string)$sale['due_date']))) . '</div>';
        }

        $customerBlock = '';
        if (!empty($cfg['show_customer'])) {
            $custName = trim((string)($sale['customer_name'] ?? '')) ?: 'CONSUMIDOR FINAL';
            $custDoc = trim((string)($sale['customer_document'] ?? $sale['document_number'] ?? ''));
            $customerBlock = '<div class="box">'
                . '<div>CLIENTE: ' . $esc(mb_strtoupper($custName)) . '</div>'
                . ($custDoc !== '' ? '<div>NIT/CC: ' . $esc($custDoc) . '</div>' : '')
                . '</div>';
        }

        $rows = '';
        foreach ($items as $item) {
            $name = $esc($item['product_name'] ?? '');
            $qty = (int)($item['quantity'] ?? 0);
            $lineTotal = $this->itemLineTotal($item);
            $rows .= '<tr>'
                . '<td class="desc">' . $name . '</td>'
                . '<td class="cant">' . $qty . '</td>'
                . '<td class="valor">' . $fmt($lineTotal) . '</td>'
                . '</tr>';
            if (!empty($cfg['show_unit_price']) && $qty > 0) {
                $unit = (float)($item['unit_price'] ?? 0);
                if ($unit > 0) {
                    $rows .= '<tr><td colspan="3" class="unit">' . $qty . ' x ' . $fmt($unit) . '</td></tr>';
                }
            }
        }

        $totalsInner = '';
        if (!empty($cfg['show_subtotal_tax'])) {
            $totalsInner .= '<div>SUBTOTAL: ' . $fmt($saleSubtotal) . '</div>';
            if ($saleTax > 0.009) {
                $totalsInner .= '<div>' . $taxName . ': ' . $fmt($saleTax) . '</div>';
            }
        }
        $deliveryClient = max(0.0, (float)($sale['delivery_fee'] ?? 0));
        if ($deliveryClient > 0.009) {
            $totalsInner .= '<div>DOMICILIO: ' . $fmt($deliveryClient) . '</div>';
        }
        $totalsInner .= '<div class="grand">TOTAL: ' . $fmt($saleTotal) . '</div>';
        $totalsBox = '<div class="box totals">' . $totalsInner . '</div>';

        $payBlock = '';
        if (!empty($cfg['show_payment_method'])) {
            $payBlock .= $this->ticketPaymentHtml($sale, $payments, $fmt, $esc);
        }
        if (!empty($cfg['show_payment_status'])) {
            $st = ($sale['payment_status'] ?? '') === 'paid' ? 'Pagado'
                : (($sale['payment_status'] ?? '') === 'partial' ? 'Parcial' : 'Pendiente');
            $payBlock .= '<div class="muted">Estado: ' . $esc($st) . '</div>';
        }
        if (!empty($cfg['show_balance_due']) && !empty($payments)) {
            $paidAmount = array_sum(array_map(static fn($p) => (float)($p['amount'] ?? 0), $payments));
            $remaining = max(0, $saleTotal - $paidAmount);
            if ($remaining > 0.009) {
                $payBlock .= '<div>Saldo: ' . $fmt($remaining) . '</div>';
            }
        }

        $notes = '';
        if (!empty($cfg['show_notes'])) {
            $cleanNotes = $this->ticketCleanNotes((string)($sale['notes'] ?? ''));
            if ($cleanNotes !== '') {
                $notes = '<div class="notes">' . $esc($cleanNotes) . '</div>';
            }
        }
        $footerText = trim((string)($cfg['footer_text'] ?? ''));
        if ($footerText === '') {
            $footerText = 'Gracias por su compra';
        }
        $footer = '<div class="muted foot">' . nl2br($esc($footerText)) . '</div>';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 2mm; }
            body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 10px; color:#111; margin:0; padding:0; }
            .co { font-size: 14px; font-weight: bold; text-align:center; margin: 0 0 2px; }
            .muted { color:#444; text-align:center; font-size:9px; margin: 1px 0; line-height:1.25; }
            .dash { border-top: 1px dashed #999; margin: 4px 0; }
            .inv { font-size: 15px; font-weight: bold; text-align:center; margin: 4px 0 2px; }
            .box { background:#f0f0f0; padding:5px 6px; margin: 4px 0; font-size:9px; }
            .box.totals { font-size:10px; }
            .grand { font-size:13px; font-weight:bold; margin-top:2px; }
            table.items { width:100%; border-collapse:collapse; margin-top:3px; }
            table.items th { font-size:8px; text-align:left; border-bottom:1px solid #bbb; padding:2px 0; color:#555; }
            table.items th.cant, table.items td.cant { text-align:center; width:14%; }
            table.items th.valor, table.items td.valor { text-align:right; width:28%; }
            table.items td { padding:2px 0; vertical-align:top; font-size:9px; }
            table.items td.desc { width:58%; }
            table.items td.unit { font-size:8px; color:#666; padding-top:0; }
            .pay { margin-top:4px; font-size:9px; }
            .notes { margin-top:4px; font-size:8px; color:#555; }
            .foot { margin-top:6px; }
        </style></head><body>
            ' . $header . '
            ' . $meta . '
            ' . $customerBlock . '
            <table class="items" cellpadding="0" cellspacing="0">
                <tr><th class="desc">DESC</th><th class="cant">CANT</th><th class="valor">VALOR</th></tr>
                ' . $rows . '
            </table>
            ' . $totalsBox . '
            <div class="pay">' . $payBlock . '</div>
            ' . $notes . '
            ' . $footer . '
        </body></html>';

        // Ancho 58mm; alto = contenido real (2 pasadas Dompdf).
        return $this->generateTicketPdf($html, count($items));
    }

    /**
     * PDF térmico 58mm con alto ajustado al contenido (evita rollo vacío).
     */
    private function generateTicketPdf(string $html, int $itemCount = 1): string
    {
        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            throw new \RuntimeException('Dompdf no está instalado. Ejecute composer install en el servidor.');
        }

        $widthPt = 164.41; // 58mm
        $probeHeight = 3200.0;
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('dpi', 72);

        $bodyHeight = 0.0;
        $measure = new Dompdf($options);
        $measure->loadHtml($html, 'UTF-8');
        $measure->setPaper([0.0, 0.0, $widthPt, $probeHeight], 'portrait');
        $measure->setCallbacks([
            [
                'event' => 'end_frame',
                'f' => static function ($frame, $canvas = null, $fontMetrics = null) use (&$bodyHeight): void {
                    $node = $frame->get_node();
                    if (!$node || strtolower((string)$node->nodeName) !== 'body') {
                        return;
                    }
                    $box = $frame->get_padding_box();
                    $h = (float)($box['h'] ?? 0);
                    if ($h > $bodyHeight) {
                        $bodyHeight = $h;
                    }
                },
            ],
        ]);
        $measure->render();
        unset($measure);

        // Fallback si el callback no midió (versión Dompdf distinta).
        if ($bodyHeight < 40.0) {
            $bodyHeight = 150.0 + max(1, $itemCount) * 22.0;
        }

        // Márgenes @page (~2mm×2) + holgura mínima de corte.
        $heightPt = max(100.0, min($probeHeight, $bodyHeight + 14.0));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0.0, 0.0, $widthPt, $heightPt], 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /** @param list<array<string,mixed>> $items */
    private function uniqueSaleItems(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = implode('|', [
                (string)($item['id'] ?? ''),
                (string)($item['product_id'] ?? ''),
                self::utf8Clean((string)($item['product_name'] ?? '')),
                (string)($item['quantity'] ?? ''),
                (string)($item['unit_price'] ?? ''),
                (string)($item['subtotal'] ?? $item['total'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $sale
     * @param list<array<string,mixed>> $payments
     */
    private function ticketPaymentHtml(array $sale, array $payments, callable $fmt, callable $esc): string
    {
        $html = '';
        $method = strtolower((string)($sale['payment_method'] ?? 'cash'));
        if ($payments !== [] && (count($payments) > 1 || $method === 'mixed')) {
            foreach ($payments as $p) {
                $label = PaymentMethodCatalog::label((string)($p['payment_method'] ?? 'cash'));
                $html .= '<div>Medio: ' . $esc($label) . ' · ' . $fmt($p['amount'] ?? 0) . '</div>';
            }
        } else {
            $label = PaymentMethodCatalog::label($method === 'mixed' && $payments !== []
                ? (string)($payments[0]['payment_method'] ?? 'cash')
                : $method);
            $html .= '<div>Medio de Pago: ' . $esc(mb_strtoupper($label)) . '</div>';
        }

        $received = null;
        $change = null;
        $cashInfo = $this->saleCashInfo($sale, (float)($sale['total'] ?? 0));
        if ($cashInfo['received'] > 0.009) {
            $received = $cashInfo['received'];
            $change = $cashInfo['change'];
        }
        if ($received !== null && $received > 0.009) {
            $html .= '<div>Billete / Recibido: ' . $fmt($received) . '</div>';
            $html .= '<div>Vueltas / Cambio: ' . $fmt($change) . '</div>';
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $sale
     * @return array{received:float,change:float}
     */
    private function saleCashInfo(array $sale, float $saleTotal = 0.0): array
    {
        $received = max(0.0, (float)($sale['cash_received'] ?? 0));
        $change = max(0.0, (float)($sale['cash_change'] ?? 0));
        $notes = (string)($sale['notes'] ?? '');
        if ($received <= 0.009 && preg_match('/Recibido(?:\s+efectivo)?:\s*([\d.,]+)/i', $notes, $m)) {
            $raw = preg_replace('/[^\d.,]/', '', $m[1]) ?? '0';
            if (function_exists('\\SoftNova\\Core\\parse_money')) {
                $received = max(0.0, \SoftNova\Core\parse_money($raw));
            } else {
                $received = max(0.0, (float)str_replace(',', '.', $raw));
            }
        }
        if ($change <= 0.009 && preg_match('/(?:Cambio|Vueltas):\s*([\d.,]+)/i', $notes, $m)) {
            $raw = preg_replace('/[^\d.,]/', '', $m[1]) ?? '0';
            if (function_exists('\\SoftNova\\Core\\parse_money')) {
                $change = max(0.0, \SoftNova\Core\parse_money($raw));
            } else {
                $change = max(0.0, (float)str_replace(',', '.', $raw));
            }
        }
        if ($received > 0.009 && $change <= 0.009 && $saleTotal > 0) {
            $change = max(0.0, round($received - $saleTotal, 2));
        }
        return ['received' => $received, 'change' => $change];
    }

    private function ticketCleanNotes(string $notes): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }
        // Quitar fragmentos de pago que ya se muestran aparte (evita repetición en mixto).
        $parts = preg_split('/\s*[·|]\s*/u', $notes) ?: [];
        $keep = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(recibido|cambio|pago\s*mixto|medio|efectivo|transferencia|dat[aá]fono|tarjeta)/i', $part)) {
                continue;
            }
            if (preg_match('/^\d+[\d.,]*\s*$/', $part)) {
                continue;
            }
            $keep[] = $part;
        }
        return trim(implode(' · ', $keep));
    }

    private static function utf8Clean(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $text = is_string($converted) ? $converted : $text;
        }
        // Repara mojibake típico UTF-8 leído como Latin-1 (CUTÃCULA → CUTÍCULA)
        if (preg_match('/Ã.|Â./u', $text)) {
            $fixed = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if (is_string($fixed) && $fixed !== '' && mb_check_encoding($fixed, 'UTF-8')) {
                $text = $fixed;
            }
        }
        return str_replace(["\u{FFFD}", '�'], '', $text);
    }

    /** @param array<string,mixed> $cfg */
    private function companyContactLinesHtml(array $cfg, string $cssClass = 'meta'): string
    {
        $lines = [];
        if (!empty($cfg['show_nit'])) {
            $nit = trim((string)($this->company['company_nit'] ?? ''));
            if ($nit !== '') {
                $lines[] = 'NIT: ' . htmlspecialchars($nit);
            }
        }
        if (!empty($cfg['show_address'])) {
            $addr = trim((string)($this->company['company_address'] ?? ''));
            if ($addr !== '') {
                $lines[] = htmlspecialchars($addr);
            }
        }
        if (!empty($cfg['show_phone'])) {
            $phone = trim((string)($this->company['company_phone'] ?? ''));
            if ($phone !== '') {
                $lines[] = 'Tel: ' . htmlspecialchars($phone);
            }
        }
        if (!empty($cfg['show_email'])) {
            $email = trim((string)($this->company['company_email'] ?? ''));
            if ($email !== '') {
                $lines[] = htmlspecialchars($email);
            }
        }
        if ($lines === []) {
            return '';
        }
        return '<div class="' . htmlspecialchars($cssClass) . '">' . implode('<br>', $lines) . '</div>';
    }

    /** @param array<string,mixed> $cfg */
    private function headerExtraHtml(array $cfg, string $cssClass = 'meta'): string
    {
        $extra = trim((string)($cfg['header_extra'] ?? ''));
        if ($extra === '') {
            return '';
        }
        return '<div class="' . htmlspecialchars($cssClass) . '" style="margin-top:4px;">' . nl2br(htmlspecialchars($extra)) . '</div>';
    }

    private function companyLogoHtml(bool $show, string $maxW = '140px', string $maxH = '70px'): string
    {
        if (!$show) {
            return '';
        }
        $rel = trim((string)($this->company['company_logo'] ?? ''));
        if ($rel === '') {
            return '';
        }
        $abs = dirname(__DIR__, 2) . '/public/' . ltrim(str_replace('\\', '/', $rel), '/');
        if (!is_readable($abs)) {
            return '';
        }
        $bin = @file_get_contents($abs);
        if ($bin === false || $bin === '') {
            return '';
        }
        $mime = 'image/png';
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($abs);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mime = $detected;
            }
        }
        return '<div style="text-align:center;margin-bottom:8px;"><img src="data:' . $mime . ';base64,'
            . base64_encode($bin) . '" style="max-width:' . $maxW . ';max-height:' . $maxH . ';"></div>';
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
     * PDF de orden de compra para enviar al proveedor.
     * Incluye el código con el que el proveedor identifica cada producto.
     */
    public function generatePurchaseOrder(array $order, array $items, array $supplier = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';

        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $taxName = htmlspecialchars($this->company['tax_name'] ?? 'IVA');

        $itemsHtml = '';
        foreach ($items as $item) {
            $ean = trim((string)($item['barcode'] ?? ''));
            $sku = trim((string)($item['supplier_sku'] ?? $item['product_code'] ?? ''));
            $itemsHtml .= '<tr>
                <td>' . htmlspecialchars($sku !== '' ? $sku : '—') . '</td>
                <td>' . htmlspecialchars($ean !== '' ? $ean : '—') . '</td>
                <td>' . htmlspecialchars((string)($item['product_name'] ?? '')) . '</td>
                <td style="text-align:center;">' . (int)($item['quantity'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['unit_cost'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['line_total'] ?? 0) . '</td>
            </tr>';
        }

        $entrega = !empty($order['expected_date'])
            ? date('d/m/Y', strtotime((string)$order['expected_date']))
            : 'A convenir';

        $html = $this->wrapHtml('ORDEN DE COMPRA', $companyName, '
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>Orden #:</strong> ' . htmlspecialchars((string)($order['order_number'] ?? '')) . '<br>
                            <strong>Fecha:</strong> ' . date('d/m/Y', strtotime((string)($order['order_date'] ?? 'now'))) . '<br>
                            <strong>Entrega esperada:</strong> ' . $entrega . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Proveedor:</strong> ' . htmlspecialchars((string)($supplier['name'] ?? $order['supplier_name'] ?? 'Sin asignar')) . '<br>
                            <strong>Documento:</strong> ' . htmlspecialchars((string)($supplier['document_number'] ?? $order['supplier_document'] ?? '—')) . '<br>
                            <strong>Contacto:</strong> ' . htmlspecialchars((string)($supplier['email'] ?? $supplier['phone'] ?? '—')) . '
                        </td>
                    </tr>
                </table>
            </div>

            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:#1E3A8A;color:#fff;">
                    <th style="text-align:left;padding:10px;">Código</th>
                    <th style="text-align:left;padding:10px;">EAN</th>
                    <th style="text-align:left;padding:10px;">Producto</th>
                    <th style="text-align:center;padding:10px;">Cant.</th>
                    <th style="text-align:right;padding:10px;">Costo Unit.</th>
                    <th style="text-align:right;padding:10px;">Total</th>
                </tr>
                ' . $itemsHtml . '
            </table>

            <table width="100%" cellpadding="6" cellspacing="0" style="margin-top:15px;">
                <tr>
                    <td width="70%" style="text-align:right;"><strong>Subtotal:</strong></td>
                    <td width="30%" style="text-align:right;">' . $fmt($order['subtotal'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td style="text-align:right;"><strong>' . $taxName . ':</strong></td>
                    <td style="text-align:right;">' . $fmt($order['tax'] ?? 0) . '</td>
                </tr>
                <tr style="font-size:16px;font-weight:bold;">
                    <td style="text-align:right;border-top:2px solid #1E3A8A;padding-top:8px;"><strong>TOTAL:</strong></td>
                    <td style="text-align:right;border-top:2px solid #1E3A8A;padding-top:8px;color:#1E3A8A;">' . $fmt($order['total'] ?? 0) . '</td>
                </tr>
            </table>

            ' . (!empty($order['notes']) ? '<p style="margin-top:18px;color:#666;"><strong>Notas:</strong> ' . htmlspecialchars((string)$order['notes']) . '</p>' : '') . '

            <p style="margin-top:22px;font-size:12px;color:#666;">
                Favor confirmar disponibilidad, precios y fecha de entrega. Los precios indicados
                corresponden al último costo registrado y están sujetos a confirmación del proveedor.
            </p>
        ');

        return $this->generate($html);
    }

    /**
     * PDF de nota de crédito de compra (proveedor).
     */
    public function generatePurchaseCreditNote(array $note, array $items = [], array $supplier = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';

        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $taxName = htmlspecialchars($this->company['tax_name'] ?? 'IVA');
        $items = $items ?: ($note['items'] ?? []);

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<tr>
                <td>' . htmlspecialchars((string)($item['product_name'] ?? '')) . '</td>
                <td style="text-align:center;">' . (int)($item['quantity'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['unit_cost'] ?? 0) . '</td>
                <td style="text-align:center;">' . number_format((float)($item['tax_rate'] ?? 0), 2, $decimal, $thousands) . '%</td>
                <td style="text-align:right;">' . $fmt($item['tax_amount'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['line_total'] ?? 0) . '</td>
            </tr>';
        }
        if ($itemsHtml === '') {
            $itemsHtml = '<tr><td colspan="6" style="text-align:center;color:#666;">Sin líneas</td></tr>';
        }

        $supplierName = (string)($supplier['name'] ?? $note['supplier_name'] ?? 'Sin asignar');
        $supplierDoc = (string)($supplier['document_number'] ?? $note['supplier_document'] ?? '—');
        $affectsStock = (int)($note['affects_stock'] ?? 0) === 1 ? 'Sí (salida de inventario)' : 'No';

        $html = $this->wrapHtml('NOTA DE CRÉDITO COMPRA', $companyName, '
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>NC #:</strong> ' . htmlspecialchars((string)($note['credit_number'] ?? '')) . '<br>
                            <strong>Fecha:</strong> ' . date('d/m/Y', strtotime((string)($note['credit_date'] ?? 'now'))) . '<br>
                            <strong>OC relacionada:</strong> ' . htmlspecialchars((string)($note['order_number'] ?? '—')) . '<br>
                            <strong>Factura proveedor:</strong> ' . htmlspecialchars((string)($note['invoice_number'] ?? $note['external_number'] ?? '—')) . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Proveedor:</strong> ' . htmlspecialchars($supplierName) . '<br>
                            <strong>Documento:</strong> ' . htmlspecialchars($supplierDoc) . '<br>
                            <strong>Afecta stock:</strong> ' . $affectsStock . '<br>
                            <strong>Estado:</strong> ' . htmlspecialchars((string)($note['status'] ?? 'posted')) . '
                        </td>
                    </tr>
                </table>
            </div>

            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:#B45309;color:#fff;">
                    <th style="text-align:left;padding:10px;">Producto</th>
                    <th style="text-align:center;padding:10px;">Cant.</th>
                    <th style="text-align:right;padding:10px;">Costo Unit.</th>
                    <th style="text-align:center;padding:10px;">' . $taxName . '%</th>
                    <th style="text-align:right;padding:10px;">' . $taxName . '</th>
                    <th style="text-align:right;padding:10px;">Total</th>
                </tr>
                ' . $itemsHtml . '
            </table>

            <table width="100%" cellpadding="6" cellspacing="0" style="margin-top:15px;">
                <tr>
                    <td width="70%" style="text-align:right;"><strong>Subtotal:</strong></td>
                    <td width="30%" style="text-align:right;">' . $fmt($note['subtotal'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td style="text-align:right;"><strong>' . $taxName . ':</strong></td>
                    <td style="text-align:right;">' . $fmt($note['tax'] ?? 0) . '</td>
                </tr>
                <tr style="font-size:16px;font-weight:bold;">
                    <td style="text-align:right;border-top:2px solid #B45309;padding-top:8px;"><strong>TOTAL NC:</strong></td>
                    <td style="text-align:right;border-top:2px solid #B45309;padding-top:8px;color:#B45309;">' . $fmt($note['total'] ?? 0) . '</td>
                </tr>
            </table>

            ' . (!empty($note['reason'])
                ? '<p style="margin-top:18px;color:#666;"><strong>Motivo:</strong> ' . htmlspecialchars((string)$note['reason']) . '</p>'
                : '') . '
            ' . (!empty($note['external_number'])
                ? '<p style="margin-top:6px;color:#666;"><strong>NC proveedor / externo:</strong> ' . htmlspecialchars((string)$note['external_number']) . '</p>'
                : '') . '
            ' . (!empty($note['accounting_entry_number'])
                ? '<p style="margin-top:6px;color:#666;"><strong>Asiento contable:</strong> ' . htmlspecialchars((string)$note['accounting_entry_number']) . '</p>'
                : '') . '

            <p style="margin-top:22px;font-size:12px;color:#666;">
                Documento interno de nota de crédito sobre compra. Reduce el saldo por pagar
                al proveedor' . ((int)($note['affects_stock'] ?? 0) === 1 ? ' y ajusta el inventario.' : '.') . '
            </p>
        ');

        return $this->generate($html);
    }

    /**
     * PDF de nota de crédito de venta (devolución de cliente).
     */
    public function generateSaleCreditNote(array $note, array $items = [], array $customer = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';

        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $taxName = htmlspecialchars($this->company['tax_name'] ?? 'IVA');
        $items = $items ?: ($note['items'] ?? []);

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<tr>
                <td>' . htmlspecialchars((string)($item['product_name'] ?? '')) . '</td>
                <td style="text-align:center;">' . (int)($item['quantity'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['unit_price'] ?? 0) . '</td>
                <td style="text-align:center;">' . number_format((float)($item['tax_rate'] ?? 0), 2, $decimal, $thousands) . '%</td>
                <td style="text-align:right;">' . $fmt($item['tax_amount'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($item['line_total'] ?? 0) . '</td>
            </tr>';
        }
        if ($itemsHtml === '') {
            $itemsHtml = '<tr><td colspan="6" style="text-align:center;color:#666;">Sin líneas</td></tr>';
        }

        $customerName = (string)($customer['name'] ?? $note['customer_name'] ?? 'Consumidor final');
        $customerDoc = (string)($customer['document_number'] ?? $note['customer_document'] ?? '—');
        $affectsStock = (int)($note['affects_stock'] ?? 0) === 1
            ? 'Sí (reingreso a inventario)'
            : 'No (producto no reingresa)';

        $html = $this->wrapHtml('NOTA DE CRÉDITO VENTA', $companyName, '
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>NC #:</strong> ' . htmlspecialchars((string)($note['credit_number'] ?? '')) . '<br>
                            <strong>Fecha:</strong> ' . date('d/m/Y', strtotime((string)($note['credit_date'] ?? 'now'))) . '<br>
                            <strong>Venta relacionada:</strong> ' . htmlspecialchars((string)($note['invoice_number'] ?? '—')) . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Cliente:</strong> ' . htmlspecialchars($customerName) . '<br>
                            <strong>Documento:</strong> ' . htmlspecialchars($customerDoc) . '<br>
                            <strong>Afecta stock:</strong> ' . $affectsStock . '<br>
                            <strong>Estado:</strong> ' . htmlspecialchars((string)($note['status'] ?? 'posted')) . '
                        </td>
                    </tr>
                </table>
            </div>

            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;padding:10px;">Producto</th>
                    <th style="text-align:center;padding:10px;">Cant.</th>
                    <th style="text-align:right;padding:10px;">P. Unit.</th>
                    <th style="text-align:center;padding:10px;">' . $taxName . '%</th>
                    <th style="text-align:right;padding:10px;">' . $taxName . '</th>
                    <th style="text-align:right;padding:10px;">Total</th>
                </tr>
                ' . $itemsHtml . '
            </table>

            <table width="100%" cellpadding="6" cellspacing="0" style="margin-top:15px;">
                <tr>
                    <td width="70%" style="text-align:right;"><strong>Subtotal:</strong></td>
                    <td width="30%" style="text-align:right;">' . $fmt($note['subtotal'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td style="text-align:right;"><strong>' . $taxName . ':</strong></td>
                    <td style="text-align:right;">' . $fmt($note['tax'] ?? 0) . '</td>
                </tr>
                <tr style="font-size:16px;font-weight:bold;">
                    <td style="text-align:right;border-top:2px solid #0D7C4A;padding-top:8px;"><strong>TOTAL NC:</strong></td>
                    <td style="text-align:right;border-top:2px solid #0D7C4A;padding-top:8px;color:#0D7C4A;">' . $fmt($note['total'] ?? 0) . '</td>
                </tr>
            </table>

            ' . (!empty($note['reason'])
                ? '<p style="margin-top:18px;color:#666;"><strong>Motivo:</strong> ' . htmlspecialchars((string)$note['reason']) . '</p>'
                : '') . '
            ' . (!empty($note['accounting_entry_number'])
                ? '<p style="margin-top:6px;color:#666;"><strong>Asiento contable:</strong> ' . htmlspecialchars((string)$note['accounting_entry_number']) . '</p>'
                : '') . '

            <p style="margin-top:22px;font-size:12px;color:#666;">
                Documento interno de nota de crédito por devolución de cliente.
                Reduce la cuenta por cobrar o genera reembolso'
                . ((int)($note['affects_stock'] ?? 0) === 1 ? ' y reingresa el producto al inventario.' : '.') . '
            </p>
        ');

        return $this->generate($html);
    }

    /**
     * Generar PDF de cierre de caja
     */
    public function generateCashClosing(array $session, array $movements, array $totals, array $sales = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        // El arqueo cuadra contra efectivo, no contra el total facturado.
        $expectedCash = $totals['expected_cash'] ?? $session['expected_amount'] ?? null;
        $diff = $totals['difference'] ?? $session['difference_amount'] ?? null;
        if ($diff === null) {
            $diff = $expectedCash !== null
                ? ((float)($session['closing_amount'] ?? 0) - (float)$expectedCash)
                : (($session['closing_amount'] ?? 0) - ($session['opening_amount'] ?? 0) - ($totals['incomes'] ?? 0) + ($totals['expenses'] ?? 0));
        }
        $diff = round((float)$diff, 2);
        
        $movementsHtml = '';
        foreach ($movements as $mov) {
            $color = $mov['type'] === 'income' ? '#10B981' : '#DC2626';
            $prefix = $mov['type'] === 'income' ? '+' : '-';
            $movementsHtml .= '<tr>
                <td>' . date('H:i', strtotime($mov['created_at'])) . '</td>
                <td>' . ($mov['type'] === 'income' ? 'Ingreso' : 'Egreso') . '</td>
                <td>' . htmlspecialchars((string)($mov['description'] ?? '')) . '</td>
                <td style="text-align:right;color:' . $color . ';">' . $prefix . $fmt($mov['amount']) . '</td>
            </tr>';
        }

        $salesHtml = '';
        $salesTotal = 0.0;
        $paymentBreakdown = [];
        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $salesTotal += (float)($sale['total'] ?? 0);
            $methodCode = PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
            if (!isset($paymentBreakdown[$methodCode])) {
                $paymentBreakdown[$methodCode] = [
                    'label' => PaymentMethodCatalog::label($methodCode),
                    'total' => 0.0,
                    'count' => 0,
                ];
            }
            $paymentBreakdown[$methodCode]['total'] += (float)($sale['total'] ?? 0);
            $paymentBreakdown[$methodCode]['count']++;

            $salesHtml .= '<tr>
                <td>' . date('H:i', strtotime($sale['sale_date'] ?? 'now')) . '</td>
                <td>' . htmlspecialchars((string)($sale['invoice_number'] ?? ('#' . ($sale['id'] ?? '')))) . '</td>
                <td>' . htmlspecialchars((string)($sale['customer_name'] ?? 'Publico general')) . '</td>
                <td>' . htmlspecialchars(PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'))) . '</td>
                <td style="text-align:right;">' . $fmt($sale['total'] ?? 0) . '</td>
            </tr>';
        }

        // Preferir desglose precalculado si viene en totals
        if (!empty($totals['payment_breakdown']) && is_array($totals['payment_breakdown'])) {
            $paymentBreakdown = $totals['payment_breakdown'];
        }

        $paymentHtml = '';
        if (!empty($paymentBreakdown)) {
            uasort($paymentBreakdown, static function ($a, $b) {
                return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
            });
            foreach ($paymentBreakdown as $code => $row) {
                $isCash = ($code === 'cash');
                $paymentHtml .= '<tr' . ($isCash ? ' style="background:#ECFDF5;"' : '') . '>
                    <td>' . htmlspecialchars((string)($row['label'] ?? '')) . ($isCash ? ' <strong>(cajón)</strong>' : '') . '</td>
                    <td style="text-align:center;">' . (int)($row['count'] ?? 0) . '</td>
                    <td style="text-align:right;' . ($isCash ? 'font-weight:bold;color:#059669;' : '') . '">' . $fmt($row['total'] ?? 0) . '</td>
                </tr>';
            }
        }
        
        $html = $this->wrapHtml('CIERRE DE CAJA - REPORTE DEL DIA', $companyName, '
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>Caja #:</strong> ' . (int)$session['id'] . '<br>
                            <strong>Apertura:</strong> ' . date('d/m/Y H:i', strtotime($session['opening_date'])) . '<br>
                            <strong>Cierre:</strong> ' . date('d/m/Y H:i', strtotime($session['closing_date'] ?? 'now')) . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Usuario:</strong> ' . htmlspecialchars($session['user_name'] ?? 'N/A') . '
                        </td>
                    </tr>
                </table>
            </div>
            
            ' . ($paymentHtml !== '' ? '
            <h3 style="margin:0 0 8px;background:#ECFDF5;border:1px solid #10B981;padding:8px 10px;border-radius:4px;">
                Totales cobrados por medio de pago
            </h3>
            <p style="margin:0 0 10px;font-size:11px;color:#555;">
                Resumen de cuánto se cobró en efectivo, Nequi, Daviplata, tarjeta y demás medios.
            </p>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:12px;margin-bottom:16px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Medio de pago</th>
                    <th style="text-align:center;border-bottom:1px solid #ddd;">Cobros</th>
                    <th style="text-align:right;border-bottom:1px solid #ddd;">Total cobrado</th>
                </tr>
                ' . $paymentHtml . '
            </table>' : '') . '

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
                    <td>Total Ingresos (movimientos)</td>
                    <td style="text-align:right;color:#10B981;">+' . $fmt($totals['incomes'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td>Total Egresos (movimientos)</td>
                    <td style="text-align:right;color:#DC2626;">-' . $fmt($totals['expenses'] ?? 0) . '</td>
                </tr>
                <tr>
                    <td>Total Ventas del periodo</td>
                    <td style="text-align:right;">' . $fmt($salesTotal) . '</td>
                </tr>
                <tr>
                    <td>Balance del periodo (todos los medios)</td>
                    <td style="text-align:right;">' . $fmt(($session['opening_amount'] ?? 0) + ($totals['incomes'] ?? 0) - ($totals['expenses'] ?? 0)) . '</td>
                </tr>
                <tr style="font-weight:bold;font-size:14px;">
                    <td style="border-top:2px solid #333;padding-top:8px;">Efectivo Esperado en Caja</td>
                    <td style="text-align:right;border-top:2px solid #333;padding-top:8px;">' . $fmt($expectedCash ?? (($session['opening_amount'] ?? 0) + ($totals['incomes'] ?? 0) - ($totals['expenses'] ?? 0))) . '</td>
                </tr>
                <tr style="font-weight:bold;font-size:14px;">
                    <td>Efectivo Contado</td>
                    <td style="text-align:right;">' . $fmt($session['closing_amount'] ?? 0) . '</td>
                </tr>
                <tr style="font-weight:bold;font-size:14px;">
                    <td style="border-top:2px solid #333;padding-top:8px;">' . ($diff < 0 ? 'Faltante' : ($diff > 0 ? 'Sobrante' : 'Diferencia')) . '</td>
                    <td style="text-align:right;border-top:2px solid #333;padding-top:8px;color:' . ($diff == 0 ? '#10B981' : '#DC2626') . ';">' . ($diff >= 0 ? '+' : '') . $fmt($diff) . '</td>
                </tr>
            </table>
            
            ' . (!empty($salesHtml) ? '
            <h3 style="margin-top:20px;">Ventas del dia</h3>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Hora</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Factura</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Cliente</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Pago</th>
                    <th style="text-align:right;border-bottom:1px solid #ddd;">Total</th>
                </tr>
                ' . $salesHtml . '
                <tr style="font-weight:bold;">
                    <td colspan="4" style="border-top:1px solid #333;padding-top:6px;">Total ventas</td>
                    <td style="text-align:right;border-top:1px solid #333;padding-top:6px;">' . $fmt($salesTotal) . '</td>
                </tr>
            </table>' : '') . '

            ' . (!empty($movements) ? '
            <h3 style="margin-top:20px;">Movimientos de caja</h3>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Hora</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Tipo</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Descripcion</th>
                    <th style="text-align:right;border-bottom:1px solid #ddd;">Monto</th>
                </tr>
                ' . $movementsHtml . '
            </table>' : '<p style="margin-top:16px;color:#666;">Sin movimientos manuales de caja en esta sesion.</p>') . '
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
     * Reporte PDF del listado de ventas (respeta filtros).
     * @param list<array<string,mixed>> $sales
     * @param array<string,mixed> $filters
     */
    public function generateSalesListReport(array $sales, array $filters = [], ?\PDO $db = null): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);

        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');
        $filterBits = [];
        if (!empty($filters['from'])) {
            $filterBits[] = 'Desde ' . htmlspecialchars((string)$filters['from']);
        }
        if (!empty($filters['to'])) {
            $filterBits[] = 'Hasta ' . htmlspecialchars((string)$filters['to']);
        }
        if (!empty($filters['q'])) {
            $filterBits[] = 'Busqueda: ' . htmlspecialchars((string)$filters['q']);
        }
        if (!empty($filters['customer_id'])) {
            $filterBits[] = 'Cliente ID ' . (int)$filters['customer_id'];
        }
        if (!empty($filters['payment_method'])) {
            $filterBits[] = 'Pago: ' . htmlspecialchars(PaymentMethodCatalog::label((string)$filters['payment_method'], $db));
        }
        if (!empty($filters['status'])) {
            $map = ['paid' => 'Pagado', 'partial' => 'Parcial', 'pending' => 'Pendiente'];
            $filterBits[] = 'Estado pago: ' . ($map[$filters['status']] ?? $filters['status']);
        }
        $rs = $filters['record_status'] ?? 'active';
        $rsMap = ['active' => 'Activas', 'cancelled' => 'Canceladas', 'all' => 'Todas'];
        $filterBits[] = 'Registro: ' . ($rsMap[$rs] ?? $rs);
        $filterLine = $filterBits !== [] ? implode(' · ', $filterBits) : 'Sin filtros adicionales';

        $rowsHtml = '';
        $sumTotal = 0.0;
        $sumPaid = 0.0;
        foreach ($sales as $s) {
            $total = (float)($s['total'] ?? 0);
            $paid = (($s['payment_status'] ?? '') === 'paid')
                ? $total
                : (float)($s['paid_amount'] ?? 0);
            $sumTotal += $total;
            $sumPaid += $paid;
            $status = (($s['status'] ?? '') === 'cancelled')
                ? 'Cancelado'
                : (($s['payment_status'] ?? '') === 'paid' ? 'Pagado'
                    : (($s['payment_status'] ?? '') === 'partial' ? 'Parcial' : 'Pendiente'));
            $rowsHtml .= '<tr>
                <td>' . htmlspecialchars((string)($s['invoice_number'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($s['customer_name'] ?? 'General')) . '</td>
                <td>' . (!empty($s['sale_date']) ? date('d/m/Y H:i', strtotime((string)$s['sale_date'])) : '') . '</td>
                <td style="text-align:right;">' . $fmt($total) . '</td>
                <td style="text-align:right;">' . $fmt($paid) . '</td>
                <td>' . htmlspecialchars(PaymentMethodCatalog::label((string)($s['payment_method'] ?? 'cash'), $db)) . '</td>
                <td>' . htmlspecialchars($status) . '</td>
            </tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="7" style="text-align:center;color:#666;padding:16px;">Sin ventas con estos filtros</td></tr>';
        }

        $html = $this->wrapHtml('Reporte de ventas', $companyName, '
            <p style="margin:0 0 12px;color:#555;font-size:11px;">' . $filterLine . '</p>
            <p style="margin:0 0 14px;"><strong>Registros:</strong> ' . count($sales)
            . ' &nbsp;|&nbsp; <strong>Total:</strong> ' . $fmt($sumTotal)
            . ' &nbsp;|&nbsp; <strong>Pagado:</strong> ' . $fmt($sumPaid) . '</p>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:10px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Factura</th>
                    <th style="text-align:left;">Cliente</th>
                    <th style="text-align:left;">Fecha</th>
                    <th style="text-align:right;">Total</th>
                    <th style="text-align:right;">Pagado</th>
                    <th style="text-align:left;">Método</th>
                    <th style="text-align:left;">Estado</th>
                </tr>
                ' . $rowsHtml . '
            </table>
        ');

        return $this->generate($html, 'A4');
    }

    /**
     * Reporte de inventario valorizado por categoría.
     * @param list<array<string,mixed>> $summary
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     */
    public function generateInventoryByCategoryReport(array $summary, array $rows, array $filters = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        $sumHtml = '';
        $totalUnits = 0;
        $totalValue = 0.0;
        foreach ($summary as $s) {
            $units = (int)($s['units'] ?? 0);
            $value = (float)($s['value'] ?? 0);
            $totalUnits += $units;
            $totalValue += $value;
            $sumHtml .= '<tr>
                <td>' . htmlspecialchars((string)($s['category_name'] ?? '')) . '</td>
                <td style="text-align:center;">' . (int)($s['products'] ?? 0) . '</td>
                <td style="text-align:right;">' . $units . '</td>
                <td style="text-align:right;">' . $fmt($value) . '</td>
            </tr>';
        }
        if ($sumHtml === '') {
            $sumHtml = '<tr><td colspan="4" style="text-align:center;color:#666;">Sin productos</td></tr>';
        }

        $detailHtml = '';
        foreach ($rows as $r) {
            $detailHtml .= '<tr>
                <td>' . htmlspecialchars((string)($r['category_name'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($r['code'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($r['name'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($r['location'] ?? '—')) . '</td>
                <td style="text-align:right;">' . (int)($r['stock'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($r['purchase_price'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($r['stock_value'] ?? 0) . '</td>
            </tr>';
        }
        if ($detailHtml === '') {
            $detailHtml = '<tr><td colspan="7" style="text-align:center;color:#666;">Sin detalle</td></tr>';
        }

        $html = $this->wrapHtml('Inventario por categoría', $companyName, '
            <p style="margin:0 0 12px;"><strong>Unidades:</strong> ' . $totalUnits
            . ' &nbsp;|&nbsp; <strong>Valor a costo:</strong> ' . $fmt($totalValue) . '</p>
            <h3 style="margin:0 0 8px;font-size:13px;">Resumen</h3>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:10px;margin-bottom:18px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Categoría</th>
                    <th>Productos</th>
                    <th style="text-align:right;">Unidades</th>
                    <th style="text-align:right;">Valor</th>
                </tr>
                ' . $sumHtml . '
            </table>
            <h3 style="margin:0 0 8px;font-size:13px;">Detalle</h3>
            <table width="100%" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-size:9px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;">Categoría</th>
                    <th style="text-align:left;">SKU</th>
                    <th style="text-align:left;">Producto</th>
                    <th style="text-align:left;">Ubicación</th>
                    <th style="text-align:right;">Stock</th>
                    <th style="text-align:right;">Costo</th>
                    <th style="text-align:right;">Valor</th>
                </tr>
                ' . $detailHtml . '
            </table>
        ');
        return $this->generate($html, 'A4');
    }

    /**
     * Reporte PDF de trazabilidad detallada.
     * @param list<array<string,mixed>> $movements
     * @param array<string,mixed> $filters
     */
    public function generateTraceabilityReport(array $movements, array $filters = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        $refLabels = [
            'purchase' => 'Compra',
            'purchase_order' => 'Orden de compra',
            'sale' => 'Venta',
            'adjustment' => 'Ajuste',
            'opening' => 'Inventario inicial',
            'return' => 'Devolución',
            'inventory_exit' => 'Salida / pérdida',
            'loan' => 'Préstamo',
            'loan_return' => 'Devolución préstamo',
            'csv_import' => 'Importación CSV',
            'csv_export' => 'Exportación CSV',
            'woocommerce' => 'WooCommerce',
            'mercadolibre' => 'Mercado Libre',
            'purchase_cancel' => 'Cancel. compra',
        ];
        $typeLabels = ['in' => 'Entrada', 'out' => 'Salida', 'adjustment' => 'Ajuste'];

        $bits = [];
        if (!empty($filters['from'])) $bits[] = 'Desde ' . htmlspecialchars((string)$filters['from']);
        if (!empty($filters['to'])) $bits[] = 'Hasta ' . htmlspecialchars((string)$filters['to']);
        if (!empty($filters['type'])) $bits[] = 'Tipo: ' . ($typeLabels[$filters['type']] ?? $filters['type']);
        if (!empty($filters['reference_type'])) {
            $bits[] = 'Origen: ' . ($refLabels[$filters['reference_type']] ?? $filters['reference_type']);
        }
        if (!empty($filters['q'])) $bits[] = 'Busqueda: ' . htmlspecialchars((string)$filters['q']);
        $filterLine = $bits !== [] ? implode(' · ', $bits) : 'Sin filtros adicionales';

        $rowsHtml = '';
        $inQty = 0;
        $outQty = 0;
        foreach ($movements as $m) {
            $type = (string)($m['type'] ?? '');
            $qty = (int)($m['quantity'] ?? 0);
            if ($type === 'in') $inQty += $qty;
            if ($type === 'out') $outQty += $qty;
            $ref = (string)($m['reference_type'] ?? '');
            $date = !empty($m['movement_date']) ? $m['movement_date'] : ($m['created_at'] ?? '');
            $rowsHtml .= '<tr>
                <td>' . ($date !== '' ? date('d/m/Y H:i', strtotime((string)$date)) : '') . '</td>
                <td>' . htmlspecialchars((string)($m['product_code'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($m['product_name'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($m['category_name'] ?? '—')) . '</td>
                <td>' . htmlspecialchars((string)($m['product_location'] ?? '—')) . '</td>
                <td>' . htmlspecialchars($typeLabels[$type] ?? $type) . '</td>
                <td style="text-align:right;">' . $qty . '</td>
                <td style="text-align:right;">' . (int)($m['stock_after'] ?? 0) . '</td>
                <td>' . htmlspecialchars($refLabels[$ref] ?? ($ref !== '' ? $ref : '—')) . '</td>
                <td style="text-align:right;">' . $fmt($m['unit_cost'] ?? 0) . '</td>
                <td>' . htmlspecialchars((string)($m['user_name'] ?? '—')) . '</td>
                <td>' . htmlspecialchars(StockService::describeMovementMotive($m)) . '</td>
            </tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="12" style="text-align:center;color:#666;padding:14px;">Sin movimientos</td></tr>';
        }

        $html = $this->wrapHtml('Trazabilidad de inventario', $companyName, '
            <p style="margin:0 0 8px;color:#555;font-size:11px;">' . $filterLine . '</p>
            <p style="margin:0 0 12px;"><strong>Movimientos:</strong> ' . count($movements)
            . ' &nbsp;|&nbsp; <strong>Entradas:</strong> ' . $inQty
            . ' &nbsp;|&nbsp; <strong>Salidas:</strong> ' . $outQty . '</p>
            <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:8px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Fecha</th>
                    <th style="text-align:left;">SKU</th>
                    <th style="text-align:left;">Producto</th>
                    <th style="text-align:left;">Categoría</th>
                    <th style="text-align:left;">Ubicación</th>
                    <th style="text-align:left;">Tipo</th>
                    <th style="text-align:right;">Cant.</th>
                    <th style="text-align:right;">Stock</th>
                    <th style="text-align:left;">Origen</th>
                    <th style="text-align:right;">Costo</th>
                    <th style="text-align:left;">Usuario</th>
                    <th style="text-align:left;">Motivo</th>
                </tr>
                ' . $rowsHtml . '
            </table>
        ');
        return $this->generate($html, 'A4');
    }

    /**
     * Informe analítico segmentado (no captura de pantalla).
     *
     * @param array<string,mixed> $data
     */
    public function generateAnalyticsReport(array $data): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        $from = htmlspecialchars((string)($data['dateFrom'] ?? ''));
        $to = htmlspecialchars((string)($data['dateTo'] ?? ''));
        $ps = $data['profitSummary'] ?? [];
        $channels = $data['marginByChannel'] ?? [];
        $channelLabels = $data['channelLabels'] ?? [];
        $topProducts = $data['topProducts'] ?? [];
        $profitability = $data['profitability'] ?? [];
        $inventoryByMonth = $data['inventoryByMonth'] ?? [];
        $inventorySalesByMonth = $data['inventorySalesByMonth'] ?? [];
        $lowStockProducts = $data['lowStockProducts'] ?? [];
        $rx = $data['receivablesAging'] ?? [];
        $payMethods = $data['paymentMethods'] ?? [];
        $categories = $data['salesByCategory'] ?? [];
        $payNames = [
            'cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia',
            'credit' => 'Crédito', 'other' => 'Otro', 'dataphone' => 'Datáfono',
        ];

        $section = static function (string $title, string $body): string {
            return '<div class="section"><h2>' . $title . '</h2>' . $body . '</div>';
        };

        $kpiHtml = '
            <table class="kpi" cellpadding="8" cellspacing="0">
                <tr>
                    <td><strong>Ventas</strong><br>' . $fmt($data['totalSales'] ?? 0) . '<br><small>' . (int)($data['totalSalesCount'] ?? 0) . ' operaciones</small></td>
                    <td><strong>Ticket promedio</strong><br>' . $fmt($data['avgTicket'] ?? 0) . '</td>
                    <td><strong>Productos activos</strong><br>' . (int)($data['totalProducts'] ?? 0) . '</td>
                    <td><strong>Clientes activos</strong><br>' . (int)($data['totalCustomers'] ?? 0) . '</td>
                </tr>
            </table>';

        $utilidadHtml = '
            <table class="grid" cellpadding="6" cellspacing="0">
                <tr><td>Ingresos (líneas)</td><td class="num">' . $fmt($ps['revenue'] ?? 0) . '</td></tr>
                <tr><td>− Costo de ventas</td><td class="num">' . $fmt($ps['cost'] ?? 0) . '</td></tr>
                <tr><td>Utilidad bruta</td><td class="num"><strong>' . $fmt($ps['gross'] ?? 0) . '</strong></td></tr>
                <tr><td>− Gastos fijos</td><td class="num">' . $fmt($ps['expenses_fixed'] ?? 0) . '</td></tr>
                <tr><td>− Gastos financieros</td><td class="num">' . $fmt($ps['expenses_financial'] ?? 0) . '</td></tr>
                <tr><td>− Otros gastos</td><td class="num">' . $fmt($ps['expenses_operating'] ?? 0) . '</td></tr>
                <tr class="total"><td>Utilidad neta</td><td class="num"><strong>' . $fmt($ps['net'] ?? 0) . '</strong></td></tr>
                <tr><td>Margen neto</td><td class="num">' . (float)($ps['margin'] ?? 0) . '%</td></tr>
            </table>';

        $channelRows = '';
        foreach ($channels as $ch) {
            $label = htmlspecialchars((string)($channelLabels[$ch['channel']] ?? $ch['channel']));
            $channelRows .= '<tr>
                <td>' . $label . '</td>
                <td class="num">' . (int)($ch['qty'] ?? 0) . '</td>
                <td class="num">' . $fmt($ch['revenue'] ?? 0) . '</td>
                <td class="num">' . $fmt($ch['cost'] ?? 0) . '</td>
                <td class="num">' . $fmt($ch['profit'] ?? 0) . '</td>
                <td class="num">' . (float)($ch['margin'] ?? 0) . '%</td>
            </tr>';
        }
        if ($channelRows === '') {
            $channelRows = '<tr><td colspan="6" style="text-align:center;color:#666;">Sin ventas por canal</td></tr>';
        }
        $channelHtml = '
            <table class="data" cellpadding="5" cellspacing="0">
                <tr class="head"><th>Canal</th><th>Unid.</th><th>Ingresos</th><th>Costo</th><th>Utilidad</th><th>Margen</th></tr>
                ' . $channelRows . '
            </table>';

        $soldRows = '';
        foreach ($topProducts as $i => $p) {
            $soldRows .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . htmlspecialchars((string)$p['name']) . '</td>
                <td class="num">' . (int)($p['total_qty'] ?? 0) . '</td>
                <td class="num">' . $fmt($p['total_sales'] ?? 0) . '</td>
                <td class="num">' . $fmt($p['profit'] ?? 0) . '</td>
            </tr>';
        }
        if ($soldRows === '') {
            $soldRows = '<tr><td colspan="5" style="text-align:center;color:#666;">Sin ventas</td></tr>';
        }

        $profitRows = '';
        foreach ($profitability as $i => $p) {
            $rev = (float)($p['revenue'] ?? 0);
            $prof = (float)($p['profit'] ?? 0);
            $m = $rev > 0 ? round(($prof / $rev) * 100, 1) : 0;
            $profitRows .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . htmlspecialchars((string)$p['name']) . '</td>
                <td class="num">' . (int)($p['qty'] ?? 0) . '</td>
                <td class="num">' . $fmt($rev) . '</td>
                <td class="num">' . $fmt($prof) . '</td>
                <td class="num">' . $m . '%</td>
            </tr>';
        }
        if ($profitRows === '') {
            $profitRows = '<tr><td colspan="6" style="text-align:center;color:#666;">Sin datos</td></tr>';
        }

        $salesByMonthMap = [];
        foreach ($inventorySalesByMonth as $row) {
            $salesByMonthMap[(string)$row['month']] = $row;
        }
        $invRows = '';
        foreach ($inventoryByMonth as $row) {
            $m = (string)$row['month'];
            $sales = $salesByMonthMap[$m] ?? ['units_sold' => 0, 'revenue' => 0];
            $invRows .= '<tr>
                <td>' . htmlspecialchars(date('M Y', strtotime($m . '-01'))) . '</td>
                <td class="num">' . (int)$row['qty_in'] . '</td>
                <td class="num">' . (int)$row['qty_out'] . '</td>
                <td class="num">' . (int)$row['qty_adj'] . '</td>
                <td class="num">' . (int)$row['movements'] . '</td>
                <td class="num">' . (int)($sales['units_sold'] ?? 0) . '</td>
                <td class="num">' . $fmt($sales['revenue'] ?? 0) . '</td>
            </tr>';
        }
        if ($invRows === '') {
            $invRows = '<tr><td colspan="7" style="text-align:center;color:#666;">Sin movimientos</td></tr>';
        }

        $lowRows = '';
        foreach ($lowStockProducts as $lp) {
            $lowRows .= '<tr>
                <td>' . htmlspecialchars((string)$lp['name']) . '</td>
                <td class="num">' . (int)$lp['stock'] . '</td>
                <td class="num">' . (int)$lp['min_stock'] . '</td>
                <td class="num">' . ((int)$lp['min_stock'] - (int)$lp['stock']) . '</td>
                <td class="num">' . $fmt($lp['sale_price'] ?? 0) . '</td>
            </tr>';
        }
        if ($lowRows === '') {
            $lowRows = '<tr><td colspan="5" style="text-align:center;color:#666;">Sin alertas de stock</td></tr>';
        }

        $rxHtml = '
            <p><strong>Total por cobrar:</strong> ' . $fmt($rx['total'] ?? 0)
            . ' &nbsp;|&nbsp; <strong>Facturas:</strong> ' . (int)($rx['cnt'] ?? 0) . '</p>
            <table class="data" cellpadding="5" cellspacing="0">
                <tr class="head"><th>0-30 días</th><th>31-60</th><th>61-90</th><th>+90</th></tr>
                <tr>
                    <td class="num">' . $fmt($rx['d0'] ?? 0) . '</td>
                    <td class="num">' . $fmt($rx['d30'] ?? 0) . '</td>
                    <td class="num">' . $fmt($rx['d60'] ?? 0) . '</td>
                    <td class="num">' . $fmt($rx['d90'] ?? 0) . '</td>
                </tr>
            </table>';

        $catRows = '';
        foreach ($categories as $cat) {
            $catRows .= '<tr><td>' . htmlspecialchars((string)$cat['category']) . '</td><td class="num">' . $fmt($cat['total'] ?? 0) . '</td></tr>';
        }
        $payRows = '';
        foreach ($payMethods as $pm) {
            $payRows .= '<tr><td>' . htmlspecialchars($payNames[$pm['payment_method']] ?? (string)$pm['payment_method'])
                . '</td><td class="num">' . (int)$pm['count'] . '</td><td class="num">' . $fmt($pm['total'] ?? 0) . '</td></tr>';
        }

        $extraFull = '';
        if (!empty($data['reportsFull'])) {
            if ($catRows !== '') {
                $extraFull .= $section('6. Ventas por categoría', '
                    <table class="data" cellpadding="5" cellspacing="0">
                        <tr class="head"><th>Categoría</th><th>Total</th></tr>' . $catRows . '
                    </table>');
            }
            if ($payRows !== '') {
                $extraFull .= $section('7. Métodos de pago', '
                    <table class="data" cellpadding="5" cellspacing="0">
                        <tr class="head"><th>Método</th><th>Ops.</th><th>Total</th></tr>' . $payRows . '
                    </table>');
            }
        }

        $body = '
            <style>
                .meta { color:#555; margin:0 0 14px; font-size:11px; }
                .section { margin:0 0 18px; page-break-inside: avoid; }
                .section h2 { margin:0 0 8px; font-size:13px; color:#0D7C4A; border-bottom:1px solid #cfe8da; padding-bottom:4px; }
                table.kpi td { width:25%; background:#f7faf8; border:1px solid #e2e8f0; vertical-align:top; font-size:11px; }
                table.grid, table.data { width:100%; border-collapse:collapse; font-size:10px; }
                table.grid td, table.data td, table.data th { border:1px solid #e5e7eb; padding:5px 6px; }
                table.data tr.head th { background:#0D7C4A; color:#fff; text-align:left; }
                table.grid tr.total td { background:#f0fdf4; }
                .num { text-align:right; white-space:nowrap; }
            </style>
            <p class="meta"><strong>Periodo:</strong> ' . $from . ' — ' . $to
            . ' &nbsp;|&nbsp; <strong>Plan:</strong> ' . htmlspecialchars((string)($data['planName'] ?? '')) . '</p>
            ' . $section('1. Resumen ejecutivo', $kpiHtml)
            . $section('2. Utilidad del periodo', $utilidadHtml)
            . $section('3. Margen por canal de ventas', $channelHtml)
            . $section('4. Productos más vendidos', '
                <table class="data" cellpadding="5" cellspacing="0">
                    <tr class="head"><th>#</th><th>Producto</th><th>Unid.</th><th>Ventas</th><th>Utilidad</th></tr>
                    ' . $soldRows . '
                </table>')
            . $section('5. Productos más rentables', '
                <table class="data" cellpadding="5" cellspacing="0">
                    <tr class="head"><th>#</th><th>Producto</th><th>Unid.</th><th>Ingresos</th><th>Utilidad</th><th>Margen</th></tr>
                    ' . $profitRows . '
                </table>')
            . $extraFull
            . $section('Inventario por mes (12 meses)', '
                <table class="data" cellpadding="5" cellspacing="0">
                    <tr class="head"><th>Mes</th><th>Entradas</th><th>Salidas</th><th>Ajustes</th><th>Movs.</th><th>Unid. vendidas</th><th>Ingresos venta</th></tr>
                    ' . $invRows . '
                </table>')
            . $section('Cuentas por cobrar', $rxHtml)
            . $section('Stock bajo', '
                <table class="data" cellpadding="5" cellspacing="0">
                    <tr class="head"><th>Producto</th><th>Stock</th><th>Mín.</th><th>Faltante</th><th>Precio</th></tr>
                    ' . $lowRows . '
                </table>');

        $html = $this->wrapHtml('Informe analítico', $companyName, $body);
        return $this->generate($html, 'A4');
    }

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