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
        $saleDiscount = round((float)($sale['discount'] ?? 0), 2);
        $saleTotal = (float)($sale['total'] ?? 0);

        $itemsHtml = '';
        foreach ($items as $item) {
            if (!empty($item['document_hidden']) || (string)($item['document_hidden'] ?? '') === '1') {
                continue;
            }
            $lineTotal = $this->itemLineTotal($item);
            $sku = trim((string)($item['product_code'] ?? $item['code'] ?? ''));
            $ean = trim((string)($item['barcode'] ?? ''));
            $nameCell = htmlspecialchars((string)($item['product_name'] ?? ''));
            $metaBits = [];
            if ($sku !== '') {
                $metaBits[] = 'SKU: ' . htmlspecialchars($sku);
            }
            if ($ean !== '') {
                $metaBits[] = 'EAN: ' . htmlspecialchars($ean);
            }
            if ($metaBits !== []) {
                $nameCell .= '<div style="font-size:10px;color:#666;margin-top:2px;">' . implode(' · ', $metaBits) . '</div>';
            }
            $itemsHtml .= '<tr><td>' . $nameCell . '</td>'
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
                . '<td width="30%" style="text-align:right;">' . $fmt($saleSubtotal) . '</td></tr>';
            if ($saleDiscount > 0.009) {
                $totalsHtml .= '<tr><td style="text-align:right;"><strong>Descuento:</strong></td>'
                    . '<td style="text-align:right;">-' . $fmt($saleDiscount) . '</td></tr>';
            }
            $totalsHtml .= '<tr><td style="text-align:right;"><strong>' . $taxName . ':</strong></td>'
                . '<td style="text-align:right;">' . $fmt($saleTax) . '</td></tr>';
        }
        $deliveryClient = $this->saleDeliveryFee($sale);
        if ($deliveryClient > 0.009) {
            $totalsHtml .= '<tr><td style="text-align:right;"><strong>Domicilio (cliente):</strong></td>'
                . '<td style="text-align:right;">' . $fmt($deliveryClient) . '</td></tr>';
        }
        $totalsHtml .= '<tr style="font-size:16px;font-weight:bold;">
                <td style="text-align:right;border-top:2px solid ' . $color . ';padding-top:8px;"><strong>TOTAL:</strong></td>
                <td style="text-align:right;border-top:2px solid ' . $color . ';padding-top:8px;color:' . $color . ';">' . $fmt($saleTotal) . '</td>
            </tr>';
        $cashInfo = $this->saleCashInfo($sale, $saleTotal, $payments);
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
    public function generateTicket58(array $sale, array $items, array $payments = [], bool $browserPrint = false): string
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
        $saleDiscount = round((float)($sale['discount'] ?? 0), 2);
        $saleTotal = (float)($sale['total'] ?? 0);
        if ($saleSubtotal <= 0 && $saleTotal > 0) {
            $saleSubtotal = max(0, $saleTotal + $saleDiscount - $saleTax);
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
            $customerBlock = '<div class="hl">'
                . '<div>CLIENTE: ' . $esc(mb_strtoupper($custName)) . '</div>'
                . ($custDoc !== '' ? '<div>NIT/CC: ' . $esc($custDoc) . '</div>' : '')
                . '</div>';
        }

        $rows = '';
        $hasTaxMarkedItems = false;
        foreach ($items as $item) {
            if (!empty($item['document_hidden']) || (string)($item['document_hidden'] ?? '') === '1') {
                continue;
            }
            $name = $esc($item['product_name'] ?? '');
            if (!empty($item['is_combo']) || (string)($item['is_combo'] ?? '') === '1') {
                if (!str_contains(mb_strtolower($name), 'combo')) {
                    $name = 'Combo: ' . $name;
                }
            }
            if ($this->saleItemHasTax($item)) {
                $name .= ' *';
                $hasTaxMarkedItems = true;
            }
            $qty = (int)($item['quantity'] ?? 0);
            $lineTotal = $this->itemLineTotal($item);
            $rows .= '<tr>'
                . '<td class="desc">' . $name . '</td>'
                . '<td class="cant">' . $qty . '</td>'
                . '<td class="valor">' . $fmt($lineTotal) . '</td>'
                . '</tr>';
            $sku = trim((string)($item['product_code'] ?? $item['code'] ?? ''));
            $ean = trim((string)($item['barcode'] ?? ''));
            $codeBits = [];
            if ($sku !== '') {
                $codeBits[] = 'SKU ' . $sku;
            }
            if ($ean !== '') {
                $codeBits[] = 'EAN ' . $ean;
            }
            if ($codeBits !== []) {
                $rows .= '<tr><td colspan="3" class="unit">' . $esc(implode(' · ', $codeBits)) . '</td></tr>';
            }
            if (!empty($cfg['show_unit_price']) && $qty > 0) {
                $unit = (float)($item['unit_price'] ?? 0);
                if ($unit > 0) {
                    $unitLine = $qty . ' x ' . $fmt($unit);
                    if ($this->saleItemHasTax($item)) {
                        $rate = round((float)($item['tax_rate'] ?? 0), 2);
                        if ($rate > 0.009) {
                            $unitLine .= ' (' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% ' . $taxName . ')';
                        }
                    }
                    $rows .= '<tr><td colspan="3" class="unit">' . $esc($unitLine) . '</td></tr>';
                }
            }
        }

        $totalsInner = '';
        if (!empty($cfg['show_subtotal_tax'])) {
            $totalsInner .= '<div>SUBTOTAL: ' . $fmt($saleSubtotal) . '</div>';
            if ($saleDiscount > 0.009) {
                $totalsInner .= '<div>DESCUENTO: -' . $fmt($saleDiscount) . '</div>';
            }
            if ($saleTax > 0.009) {
                $totalsInner .= '<div>' . $taxName . ': ' . $fmt($saleTax) . '</div>';
            }
        }
        $deliveryClient = $this->saleDeliveryFee($sale);
        if ($deliveryClient > 0.009) {
            $totalsInner .= '<div>DOMICILIO: ' . $fmt($deliveryClient) . '</div>';
        }
        $totalsBox = ($totalsInner !== '' ? '<div class="totals-pre">' . $totalsInner . '</div>' : '')
            . '<div class="hl grand">TOTAL: ' . $fmt($saleTotal) . '</div>';

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
        $taxLegend = '';
        if ($hasTaxMarkedItems) {
            $taxLegend = '<div class="tax-legend">* Producto gravado con ' . $taxName
                . '. El impuesto discriminado en el total corresponde a estos ítems.</div>';
        }
        $footer = '<div class="muted foot">' . nl2br($esc($footerText)) . '</div>';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 2mm; }
            body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 10px; color:#111; margin:0; padding:0; background:#fff; }
            .co { font-size: 12px; font-weight: bold; text-align:center; margin: 0 0 2px; }
            .muted { color:#444; text-align:center; font-size:9px; margin: 1px 0; line-height:1.25; }
            .dash { border-top: 1px dashed #999; margin: 4px 0; }
            .inv { font-size: 12px; font-weight: bold; text-align:center; margin: 4px 0 2px; }
            .hl { background:transparent; border-top:1px solid #111; border-bottom:1px solid #111; padding:4px 0; margin: 5px 0; font-size:9px; }
            .totals-pre { margin: 4px 0 0; font-size:10px; }
            .grand { font-size:12px; font-weight:bold; }
            table.items { width:100%; border-collapse:collapse; margin-top:3px; }
            table.items th { font-size:8px; text-align:left; border-bottom:1px solid #bbb; padding:2px 0; color:#555; }
            table.items th.cant, table.items td.cant { text-align:center; width:14%; }
            table.items th.valor, table.items td.valor { text-align:right; width:28%; }
            table.items td { padding:2px 0; vertical-align:top; font-size:9px; }
            table.items td.desc { width:58%; }
            table.items td.unit { font-size:8px; color:#666; padding-top:0; }
            .pay { margin-top:4px; font-size:9px; }
            .notes { margin-top:4px; font-size:8px; color:#555; }
            .tax-legend { margin-top:5px; font-size:7px; color:#555; text-align:left; line-height:1.35; }
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
            ' . $taxLegend . '
            ' . $footer . '
        </body></html>';

        // Ancho 58mm; alto = contenido real (2 pasadas Dompdf).
        if ($browserPrint) {
            return $this->wrapTicketForBrowserPrint($html);
        }
        return $this->generateTicketPdf($html, count($items));
    }

    private function wrapTicketForBrowserPrint(string $html): string
    {
        $extraCss = '@media print { @page { size: 58mm auto; margin: 2mm; } .no-print { display:none !important; } body { width:54mm; } }'
            . 'html,body { width:58mm; margin:0 auto; background:#fff; }'
            . '.no-print { text-align:center; margin:10px 4px 14px; font-family:sans-serif; font-size:12px; color:#333; }'
            . '.no-print button { padding:8px 16px; font-size:14px; cursor:pointer; }';
        $html = preg_replace('/<style>/', '<style>' . $extraCss, $html, 1) ?? $html;
        $toolbar = '<div class="no-print">'
            . '<button type="button" onclick="window.print()">Imprimir tirilla</button>'
            . '<p>En el diálogo elija la impresora térmica 58 mm. Para no elegirla cada vez, configúrela como predeterminada en Windows.</p>'
            . '</div>';
        $html = preg_replace('/<body>/', '<body>' . $toolbar, $html, 1) ?? $html;
        $script = '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},300);});</script>';
        return str_replace('</body>', $script . '</body>', $html);
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
        $cashInfo = $this->saleCashInfo($sale, (float)($sale['total'] ?? 0), $payments);
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
     * @param list<array<string,mixed>> $payments
     * @return array{received:float,change:float}
     */
    private function saleCashInfo(array $sale, float $saleTotal = 0.0, array $payments = []): array
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
        $due = $saleTotal;
        $cashPaid = 0.0;
        foreach ($payments as $p) {
            if (PaymentMethodCatalog::affectsCash((string)($p['payment_method'] ?? ''))) {
                $cashPaid += (float)($p['amount'] ?? 0);
            }
        }
        if ($cashPaid > 0.009) {
            $due = $cashPaid;
        }
        if (function_exists('\\SoftNova\\Core\\cash_change_amount')) {
            $change = \SoftNova\Core\cash_change_amount($received, $due, $change);
        } elseif ($received > 0.009 && ($change <= 0.009 || $change > $received + 0.05 || $change >= 1000000000.0)) {
            $change = max(0.0, round($received - $due, 2));
        }
        return ['received' => $received, 'change' => $change];
    }

    /** Domicilio cobrado al cliente (columna o nota de la venta). */
    private function saleDeliveryFee(array $sale): float
    {
        $fee = max(0.0, (float)($sale['delivery_fee'] ?? 0));
        if ($fee > 0.009) {
            return round($fee, 2);
        }
        $notes = (string)($sale['notes'] ?? '');
        if ($notes === '') {
            return 0.0;
        }
        if (preg_match('/cliente\s+([\d.,]+)/i', $notes, $m) && stripos($notes, 'Domicilio') !== false) {
            $raw = $m[1];
        } elseif (preg_match('/Domicilio:\s*([\d.,]+)/i', $notes, $m)) {
            $raw = $m[1];
        } else {
            return 0.0;
        }
        if (function_exists('\\SoftNova\\Core\\parse_money')) {
            return max(0.0, round(\SoftNova\Core\parse_money($raw), 2));
        }
        return max(0.0, round((float)str_replace(',', '.', $raw), 2));
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
            if (preg_match('/^(recibido|cambio|vueltas|pago\s*mixto|medio|efectivo|transferencia|dat[aá]fono|tarjeta|domicilio)/i', $part)) {
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

    /** Ítem con IVA gravado (tasa o monto de impuesto en la línea). */
    private function saleItemHasTax(array $item): bool
    {
        $rate = (float)($item['tax_rate'] ?? 0);
        if ($rate > 0.009) {
            return true;
        }
        return (float)($item['tax_amount'] ?? 0) > 0.009;
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
        
        $validDays = 15;
        $validUntilLabel = '';
        if (!empty($quote['valid_until']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$quote['valid_until'])) {
            $validUntilTs = strtotime(substr((string)$quote['valid_until'], 0, 10));
            $createdTs = strtotime((string)($quote['created_at'] ?? $quote['quote_date'] ?? 'now'));
            if ($validUntilTs && $createdTs) {
                $validDays = max(1, (int)ceil(($validUntilTs - $createdTs) / 86400));
            }
            $validUntilLabel = date('d/m/Y', $validUntilTs ?: time());
        } else {
            $validDays = !empty($quote['valid_days']) ? max(1, (int)$quote['valid_days']) : 15;
            $validUntilLabel = date('d/m/Y', strtotime('+' . $validDays . ' days', strtotime($quote['created_at'] ?? 'now')));
        }
        
        $html = $this->wrapHtml('COTIZACIÓN', $companyName, '
            <div style="margin-bottom:20px;padding:15px;background:#FFF3E0;border-left:4px solid #FF9800;border-radius:4px;">
                <strong>Documento informativo — No es una factura</strong><br>
                <span style="font-size:12px;color:#666;">Válida hasta: ' . $validUntilLabel . '</span>
            </div>
            
            <div style="margin-bottom:20px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="50%">
                            <strong>Cotización #:</strong> ' . htmlspecialchars($quote['quote_number'] ?? 'N/A') . '<br>
                            <strong>Fecha:</strong> ' . date('d/m/Y', strtotime($quote['created_at'] ?? $quote['quote_date'] ?? 'now')) . '<br>
                            <strong>Cliente:</strong> ' . htmlspecialchars($quote['customer_name'] ?? 'Cliente general') . '
                        </td>
                        <td width="50%" style="text-align:right;">
                            <strong>Estado:</strong> ' . ucfirst($quote['status'] ?? 'pending') . '<br>
                            <strong>Válida hasta:</strong> ' . $validUntilLabel . '
                        </td>
                    </tr>
                </table>
            </div>
            
            <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
                <tr style="background:#0F766E;color:#fff;">
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
                    <td style="text-align:right;border-top:2px solid #0F766E;padding-top:8px;"><strong>TOTAL:</strong></td>
                    <td style="text-align:right;border-top:2px solid #0F766E;padding-top:8px;color:#0F766E;">' . $fmt($quote['total'] ?? 0) . '</td>
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
    public function generateCashClosing(
        array $session,
        array $movements,
        array $totals,
        array $sales = [],
        array $adminExpenses = [],
        bool $showCostProfit = false
    ): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        // El arqueo cuadra contra efectivo, no contra el total facturado.
        $opening = (float)($session['opening_amount'] ?? 0);
        $expectedCash = $totals['expected_cash'] ?? $session['expected_amount'] ?? null;
        if ($expectedCash === null) {
            $expectedCash = $opening + (float)($totals['cash_incomes'] ?? $totals['incomes'] ?? 0)
                - (float)($totals['expenses'] ?? 0);
        }
        $expectedCash = (float)$expectedCash;
        $cashSales = (float)($totals['cash_sales'] ?? 0);
        if ($cashSales <= 0.009 && !empty($totals['payment_breakdown']['cash']['total'])) {
            $cashSales = (float)$totals['payment_breakdown']['cash']['total'];
        }
        $otherCashIn = (float)($totals['other_cash_incomes'] ?? 0);
        if ($otherCashIn <= 0.009 && isset($totals['cash_incomes'])) {
            $otherCashIn = max(0.0, (float)$totals['cash_incomes'] - $cashSales);
        }
        $expenses = (float)($totals['expenses'] ?? 0);
        $cashDayNet = (float)($totals['cash_day_net'] ?? ($expectedCash - $opening));
        $diff = $totals['difference'] ?? $session['difference_amount'] ?? null;
        if ($diff === null) {
            $diff = (float)($session['closing_amount'] ?? 0) - $expectedCash;
        }
        $diff = round((float)$diff, 2);
        
        $movementsHtml = '';
        foreach ($movements as $mov) {
            $color = $mov['type'] === 'income' ? '#10B981' : '#DC2626';
            $prefix = $mov['type'] === 'income' ? '+' : '-';
            $movementsHtml .= '<tr>
                <td>' . date('H:i', strtotime($mov['created_at'])) . '</td>
                <td>' . htmlspecialchars((string)($mov['user_name'] ?? 'Sin usuario')) . '</td>
                <td>' . ($mov['type'] === 'income' ? 'Ingreso' : 'Egreso') . '</td>
                <td>' . htmlspecialchars((string)($mov['description'] ?? '')) . '</td>
                <td style="text-align:right;color:' . $color . ';">' . $prefix . $fmt($mov['amount']) . '</td>
            </tr>';
        }

        $salesHtml = '';
        $salesTotal = 0.0;
        $salesDiscountTotal = (float)($totals['sales_discount_total'] ?? 0);
        $salesSubtotalBeforeDiscount = (float)($totals['sales_subtotal_before_discount'] ?? 0);
        $paymentBreakdown = [];
        $computedDiscount = 0.0;
        $computedSubtotal = 0.0;
        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $saleTotal = (float)($sale['total'] ?? 0);
            $salesTotal += $saleTotal;
            $disc = round((float)($sale['discount'] ?? 0), 2);
            $sub = round((float)($sale['subtotal'] ?? 0), 2);
            if ($sub <= 0.009) {
                $sub = round($saleTotal + max(0.0, $disc), 2);
            }
            $computedDiscount += max(0.0, $disc);
            $computedSubtotal += max(0.0, $sub);
            $methodCode = PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
            if (!isset($paymentBreakdown[$methodCode])) {
                $paymentBreakdown[$methodCode] = [
                    'label' => PaymentMethodCatalog::label($methodCode),
                    'total' => 0.0,
                    'count' => 0,
                ];
            }
            $paymentBreakdown[$methodCode]['total'] += $saleTotal;
            $paymentBreakdown[$methodCode]['count']++;

            $salesHtml .= '<tr>
                <td>' . date('H:i', strtotime($sale['sale_date'] ?? 'now')) . '</td>
                <td>' . htmlspecialchars((string)($sale['invoice_number'] ?? ('#' . ($sale['id'] ?? '')))) . '</td>
                <td>' . htmlspecialchars((string)($sale['customer_name'] ?? 'Publico general')) . '</td>
                <td>' . htmlspecialchars(PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'))) . '</td>
                <td style="text-align:right;">' . $fmt($saleTotal) . '</td>
            </tr>';
        }
        if ($salesDiscountTotal <= 0.009 && $computedDiscount > 0.009) {
            $salesDiscountTotal = $computedDiscount;
        }
        if ($salesSubtotalBeforeDiscount <= 0.009 && $computedSubtotal > 0.009) {
            $salesSubtotalBeforeDiscount = $computedSubtotal;
        }
        if ($salesSubtotalBeforeDiscount <= 0.009 && $salesTotal > 0.009) {
            $salesSubtotalBeforeDiscount = round($salesTotal + $salesDiscountTotal, 2);
        }
        // Preferir totales de sesión (misma fuente que el modal / WhatsApp).
        if (isset($totals['sales_total'])) {
            $salesTotal = (float)$totals['sales_total'];
        }
        if (isset($totals['sales_discount_total'])) {
            $salesDiscountTotal = (float)$totals['sales_discount_total'];
        }
        if (isset($totals['sales_subtotal_before_discount'])
            && (float)$totals['sales_subtotal_before_discount'] > 0.009
        ) {
            $salesSubtotalBeforeDiscount = (float)$totals['sales_subtotal_before_discount'];
        }

        // Preferir desglose precalculado si viene en totals
        if (!empty($totals['payment_breakdown']) && is_array($totals['payment_breakdown'])) {
            $paymentBreakdown = $totals['payment_breakdown'];
        }

        $paymentHtml = '';
        $paymentsSum = 0.0;
        if (!empty($paymentBreakdown)) {
            uasort($paymentBreakdown, static function ($a, $b) {
                return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
            });
            foreach ($paymentBreakdown as $code => $row) {
                $isCash = ($code === 'cash');
                $paymentsSum += (float)($row['total'] ?? 0);
                $paymentHtml .= '<tr' . ($isCash ? ' style="background:#ECFDF5;"' : '') . '>
                    <td>' . htmlspecialchars((string)($row['label'] ?? '')) . ($isCash ? ' <strong>(cajón)</strong>' : '') . '</td>
                    <td style="text-align:center;">' . (int)($row['count'] ?? 0) . '</td>
                    <td style="text-align:right;' . ($isCash ? 'font-weight:bold;color:#059669;' : '') . '">' . $fmt($row['total'] ?? 0) . '</td>
                </tr>';
            }
        }

        $salesTaxTotal = (float)($totals['sales_tax_total'] ?? 0);
        $salesDeliveryTotal = (float)($totals['sales_delivery_total'] ?? 0);
        if ($salesTaxTotal <= 0.009 || $salesDeliveryTotal <= 0.009) {
            $computedTax = 0.0;
            $computedDel = 0.0;
            foreach ($sales as $sale) {
                if (($sale['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $computedTax += max(0.0, round((float)($sale['tax'] ?? 0), 2));
                $computedDel += max(0.0, round((float)($sale['delivery_fee'] ?? 0), 2));
            }
            if ($salesTaxTotal <= 0.009) {
                $salesTaxTotal = $computedTax;
            }
            if ($salesDeliveryTotal <= 0.009) {
                $salesDeliveryTotal = $computedDel;
            }
        }

        $totalsForRecon = array_merge($totals, [
            'sales_subtotal_before_discount' => $salesSubtotalBeforeDiscount,
            'sales_discount_total' => $salesDiscountTotal,
            'sales_total' => $salesTotal,
            'sales_tax_total' => $salesTaxTotal,
            'sales_delivery_total' => $salesDeliveryTotal,
            'payment_breakdown' => $paymentBreakdown,
            'expected_cash' => $expectedCash,
            'cash_sales' => $cashSales,
            'other_cash_incomes' => $otherCashIn,
            'expenses' => $expenses,
            'difference' => $diff,
            'counted_cash' => $session['closing_amount'] ?? null,
        ]);

        // Asegurar campos de costo/utilidad si vienen en $totals.
        foreach (['sales_cost_total', 'gross_profit', 'gross_margin_pct', 'ventas_totales_con_base',
            'sales_cancelled_total', 'sales_cancelled_count'] as $k) {
            if (isset($totals[$k]) && !isset($totalsForRecon[$k])) {
                $totalsForRecon[$k] = $totals[$k];
            }
        }
        if (!isset($totalsForRecon['ventas_totales_con_base'])) {
            $totalsForRecon['ventas_totales_con_base'] = round($salesTotal + $opening, 2);
        }

        $summaryHtml = CashClosingReportBuilder::buildExecutiveSummaryHtml(
            $session,
            $totalsForRecon,
            $fmt,
            $showCostProfit
        );
        $activityHtml = CashClosingReportBuilder::buildUnifiedActivityHtml($sales, $movements, $fmt);

        $html = $this->wrapHtml('CIERRE DE CAJA - REPORTE DEL DIA', $companyName, '
            <div style="margin-bottom:14px;">
                <table width="100%" cellpadding="4" cellspacing="0">
                    <tr>
                        <td width="55%">
                            <strong>Caja #:</strong> ' . (int)$session['id'] . '<br>
                            <strong>Apertura:</strong> ' . date('d/m/Y H:i', strtotime($session['opening_date'])) . '<br>
                            <strong>Cierre:</strong> ' . date('d/m/Y H:i', strtotime($session['closing_date'] ?? 'now')) . '
                        </td>
                        <td width="45%" style="text-align:right;">
                            <strong>Usuario:</strong> ' . htmlspecialchars($session['user_name'] ?? 'N/A') . '
                        </td>
                    </tr>
                </table>
            </div>

            ' . $summaryHtml . '

            <h3 style="margin:18px 0 8px;font-size:13px;background:#F1F5F9;border:1px solid #CBD5E1;padding:8px 10px;">
                Movimientos de caja + Ventas
            </h3>
            <p style="margin:0 0 8px;font-size:10px;color:#64748B;">
                Una sola lista del día: cada venta aparece una vez; los egresos/ingresos manuales del cajón se suman sin duplicar cobros de venta.
            </p>
            ' . $activityHtml . '

            ' . $this->cashClosingAdminExpensesHtml($adminExpenses, $fmt) . '
        ');

        return $this->generate($html);
    }

    /**
     * Bloque HTML de egresos administrativos (informativo, no afecta cajón).
     *
     * @param list<array<string,mixed>> $adminExpenses
     */
    private function cashClosingAdminExpensesHtml(array $adminExpenses, callable $fmt): string
    {
        if ($adminExpenses === []) {
            return '';
        }

        $rows = '';
        $total = 0.0;
        foreach ($adminExpenses as $row) {
            $amount = (float)($row['amount'] ?? 0);
            $total += $amount;
            $payLabel = PaymentMethodCatalog::label((string)($row['payment_method'] ?? 'other'));
            $desc = htmlspecialchars((string)($row['description'] ?? ''));
            $cat = htmlspecialchars((string)($row['category_name'] ?? 'Administrativo'));
            $note = trim((string)($row['notes'] ?? ''));
            if ($note !== '') {
                $desc .= ' — ' . htmlspecialchars($note);
            }
            $rows .= '<tr>
                <td>' . date('d/m/Y', strtotime((string)($row['expense_date'] ?? 'now'))) . '</td>
                <td>' . htmlspecialchars((string)($row['user_name'] ?? 'Sin usuario')) . '</td>
                <td>' . $cat . '</td>
                <td>' . $desc . '</td>
                <td>' . htmlspecialchars($payLabel) . '</td>
                <td style="text-align:right;">' . $fmt($amount) . '</td>
            </tr>';
        }

        return '
            <h3 style="margin-top:24px;background:#EFF6FF;border:1px solid #93C5FD;padding:8px 10px;border-radius:4px;">
                Egresos administrativos (informativo)
            </h3>
            <p style="margin:0 0 10px;font-size:11px;color:#555;">
                Arriendo, IVA, retenciones y similares registrados en Gastos. <strong>No descuentan el cajón físico</strong> ni alteran el arqueo de efectivo.
            </p>
            <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr style="background:#f5f5f5;">
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Fecha</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Usuario</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Categoría</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Descripción</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">Pago</th>
                    <th style="text-align:right;border-bottom:1px solid #ddd;">Monto</th>
                </tr>
                ' . $rows . '
                <tr style="font-weight:bold;">
                    <td colspan="5" style="border-top:1px solid #333;padding-top:6px;">Total administrativos del día</td>
                    <td style="text-align:right;border-top:1px solid #333;padding-top:6px;">' . $fmt($total) . '</td>
                </tr>
            </table>';
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
            ['Incapacidad (' . (int)($item['incapacity_days'] ?? 0) . ' días)', $item['incapacity_pay'] ?? 0],
        ];
        if ((float)($item['overtime_day_amount'] ?? 0) > 0 || (float)($item['overtime_day_hours'] ?? 0) > 0) {
            $rows[] = ['HE diurnas HED (' . number_format((float)($item['overtime_day_hours'] ?? 0), 2, ',', '.') . ' h)', $item['overtime_day_amount'] ?? 0];
        }
        if ((float)($item['overtime_night_amount'] ?? 0) > 0 || (float)($item['overtime_night_hours'] ?? 0) > 0) {
            $rows[] = ['HE nocturnas HEN (' . number_format((float)($item['overtime_night_hours'] ?? 0), 2, ',', '.') . ' h)', $item['overtime_night_amount'] ?? 0];
        }
        if ((float)($item['night_surcharge_amount'] ?? 0) > 0 || (float)($item['night_surcharge_hours'] ?? 0) > 0) {
            $rows[] = ['Recargo noct. RNO (' . number_format((float)($item['night_surcharge_hours'] ?? 0), 2, ',', '.') . ' h)', $item['night_surcharge_amount'] ?? 0];
        }
        if ((float)($item['dominical_amount'] ?? 0) > 0 || (float)($item['dominical_hours'] ?? 0) > 0) {
            $rows[] = ['Dominical/festivo (' . number_format((float)($item['dominical_hours'] ?? 0), 2, ',', '.') . ' h)', $item['dominical_amount'] ?? 0];
        }
        if ((float)($item['overtime_dom_day_amount'] ?? 0) > 0 || (float)($item['overtime_dom_day_hours'] ?? 0) > 0) {
            $rows[] = ['HE dom. diurna HEDD (' . number_format((float)($item['overtime_dom_day_hours'] ?? 0), 2, ',', '.') . ' h)', $item['overtime_dom_day_amount'] ?? 0];
        }
        if ((float)($item['overtime_dom_night_amount'] ?? 0) > 0 || (float)($item['overtime_dom_night_hours'] ?? 0) > 0) {
            $rows[] = ['HE dom. nocturna HEND (' . number_format((float)($item['overtime_dom_night_hours'] ?? 0), 2, ',', '.') . ' h)', $item['overtime_dom_night_amount'] ?? 0];
        }
        if ((float)($item['commission_amount'] ?? 0) > 0) {
            $q = PayrollService::quincenasInPeriod((int)($run['days_worked'] ?? 30));
            $rows[] = ['Comisión fija (' . $q . ' quincena' . ($q > 1 ? 's' : '') . ', no salarial)', $item['commission_amount']];
        }
        $rows[] = ['(+) Total devengado', $item['gross_pay'] ?? 0];
        $cesProv = (float)($item['cesantias'] ?? 0) + (float)($item['cesantias_interest'] ?? 0);
        if ($cesProv > 0) {
            $rows[] = ['(info) Provisión cesantías (no neto)', $cesProv];
        }
        if ((float)($item['vacation_provision'] ?? 0) > 0) {
            $rows[] = ['(info) Provisión vacaciones (no neto)', $item['vacation_provision']];
        }
        $rows[] = ['(-) Salud empleado', -1 * (float)($item['health_employee'] ?? 0)];
        $rows[] = ['(-) Pensión empleado', -1 * (float)($item['pension_employee'] ?? 0)];
        $rows[] = ['(=) Neto a pagar', $item['net_pay'] ?? 0];
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
                <tr><td>Provisión vacaciones</td><td style="text-align:right;">' . $fmt($run['vacation_total'] ?? 0) . '</td></tr>
                <tr><td>Incapacidades</td><td style="text-align:right;">' . $fmt($run['incapacity_total'] ?? 0) . '</td></tr>
                <tr><td>HE / dominicales</td><td style="text-align:right;">' . $fmt($run['overtime_total'] ?? 0) . '</td></tr>
                <tr><td>Comisiones fijas (no salariales)</td><td style="text-align:right;">' . $fmt($run['commission_total'] ?? 0) . '</td></tr>
                <tr><td>Deducciones empleados</td><td style="text-align:right;">' . $fmt($run['deductions_total'] ?? 0) . '</td></tr>
                <tr><td>Aportes empleador</td><td style="text-align:right;">' . $fmt($run['employer_total'] ?? 0) . '</td></tr>
                <tr><td>Parafiscales</td><td style="text-align:right;">' . $fmt($run['parafiscal_total'] ?? 0) . '</td></tr>
                <tr style="font-weight:bold;"><td>Neto a pagar</td><td style="text-align:right;">' . $fmt($run['net_total'] ?? 0) . '</td></tr>
            </table>
        ');
        return $this->generate($html);
    }

    /**
     * Listado de empleados de nómina (respeta filtros de la pestaña Empleados).
     * @param list<array<string,mixed>> $employees
     * @param array<int, array<string,mixed>> $schedules
     * @param list<string> $filterText
     */
    public function generatePayrollEmployeesReport(array $employees, array $schedules, array $filterText = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $fmt = fn ($n) => $currency . ' ' . number_format((float)$n, $decimals, $this->currency['decimal'] ?? ',', $this->currency['thousands'] ?? '.');
        $esc = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $companyName = $esc($this->company['company_name'] ?? 'Mi Empresa');

        $rows = '';
        $salaryTotal = $commissionTotal = 0.0;
        $active = 0;
        foreach ($employees as $e) {
            $isActive = ($e['status'] ?? '') === 'active';
            $active += $isActive ? 1 : 0;
            $salaryTotal += (float)($e['salary'] ?? 0);
            $commissionTotal += (float)($e['fixed_commission'] ?? 0);
            $rows .= '<tr>
                <td>' . $esc(trim(($e['document_type'] ?? '') . ' ' . ($e['document_number'] ?? ''))) . '</td>
                <td>' . $esc(trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''))) . '</td>
                <td>' . $esc(($e['position_title'] ?? '') ?: '-') . '</td>
                <td>' . (!empty($e['hire_date']) ? date('d/m/Y', strtotime((string)$e['hire_date'])) : '-') . '</td>
                <td style="text-align:right;">' . $fmt($e['salary'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($e['fixed_commission'] ?? 0) . '</td>
                <td style="text-align:center;">' . (!empty($e['has_transport_aid']) ? 'Sí' : 'No') . '</td>
                <td>' . $esc($schedules[(int)($e['schedule_id'] ?? 0)]['name'] ?? 'Sin horario') . '</td>
                <td>' . ($isActive ? 'Activo' : 'Inactivo') . '</td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9" style="text-align:center;color:#666;">Sin empleados para los filtros aplicados</td></tr>';
        }

        $html = $this->wrapHtml('EMPLEADOS DE NÓMINA', $companyName, '
            <p style="margin:0 0 6px;">' . ($filterText ? $esc(implode(' · ', $filterText)) : 'Todos los empleados') . '</p>
            <p style="margin:0 0 12px;"><strong>Empleados:</strong> ' . count($employees) . ' (' . $active . ' activos)
               &nbsp;|&nbsp; <strong>Total salarios:</strong> ' . $fmt($salaryTotal) . '
               &nbsp;|&nbsp; <strong>Comisiones fijas por quincena:</strong> ' . $fmt($commissionTotal) . '</p>
            <table width="100%" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-size:9px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Documento</th><th style="text-align:left;">Nombre</th><th style="text-align:left;">Cargo</th>
                    <th style="text-align:left;">Ingreso</th><th style="text-align:right;">Salario</th><th style="text-align:right;">Comisión quinc.</th>
                    <th>Aux. transp.</th><th style="text-align:left;">Horario</th><th style="text-align:left;">Estado</th>
                </tr>
                ' . $rows . '
            </table>
        ');
        return $this->generate($html, 'A4');
    }

    /**
     * Listado de liquidaciones de nómina con totales (respeta filtros de la pestaña Liquidaciones).
     * @param list<array<string,mixed>> $runs
     * @param list<string> $filterText
     * @param array<string,string> $statusLabels
     */
    public function generatePayrollRunsReport(array $runs, array $filterText = [], array $statusLabels = []): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = (int)($this->currency['decimals'] ?? 0);
        $fmt = fn ($n) => $currency . ' ' . number_format((float)$n, $decimals, $this->currency['decimal'] ?? ',', $this->currency['thousands'] ?? '.');
        $esc = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $companyName = $esc($this->company['company_name'] ?? 'Mi Empresa');

        $keys = ['gross_total', 'deductions_total', 'net_total', 'employer_total', 'parafiscal_total', 'prima_total', 'cesantias_total', 'vacation_total'];
        $totals = array_fill_keys($keys, 0.0);
        $rows = '';
        foreach ($runs as $r) {
            $cancelled = ($r['status'] ?? '') === 'cancelled';
            if (!$cancelled) {
                foreach ($keys as $k) {
                    $totals[$k] += (float)($r[$k] ?? 0);
                }
            }
            $rows .= '<tr' . ($cancelled ? ' style="color:#999;"' : '') . '>
                <td>' . $esc($r['run_number'] ?? '') . '</td>
                <td>' . $esc($r['period_label'] ?? '') . '</td>
                <td>' . date('d/m/Y', strtotime((string)($r['pay_date'] ?? 'now'))) . '</td>
                <td style="text-align:center;">' . (int)($r['employee_count'] ?? 0) . '</td>
                <td>' . $esc($statusLabels[$r['status'] ?? ''] ?? ($r['status'] ?? '')) . '</td>';
            foreach ($keys as $k) {
                $rows .= '<td style="text-align:right;">' . $fmt($r[$k] ?? 0) . '</td>';
            }
            $rows .= '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="13" style="text-align:center;color:#666;">Sin liquidaciones para los filtros aplicados</td></tr>';
        }
        $totalRow = '<tr style="font-weight:bold;background:#f5f5f5;"><td colspan="5">Total (sin canceladas)</td>';
        foreach ($keys as $k) {
            $totalRow .= '<td style="text-align:right;">' . $fmt($totals[$k]) . '</td>';
        }
        $totalRow .= '</tr>';

        $html = $this->wrapHtml('LIQUIDACIONES DE NÓMINA', $companyName, '
            <p style="margin:0 0 12px;">' . ($filterText ? $esc(implode(' · ', $filterText)) : 'Todas las liquidaciones') . ' · ' . count($runs) . ' liquidación(es)</p>
            <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:8px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Nº</th><th style="text-align:left;">Periodo</th><th style="text-align:left;">Pago</th>
                    <th>Emp.</th><th style="text-align:left;">Estado</th>
                    <th style="text-align:right;">Bruto</th><th style="text-align:right;">Deducciones</th><th style="text-align:right;">Neto</th>
                    <th style="text-align:right;">Aportes emp.</th><th style="text-align:right;">Parafiscales</th>
                    <th style="text-align:right;">Primas</th><th style="text-align:right;">Cesantías</th><th style="text-align:right;">Vacaciones</th>
                </tr>
                ' . $rows . $totalRow . '
            </table>
        ');
        return $this->generate($html, [0, 0, 841.89, 595.28]);
    }

    /**
     * Reporte tabular de consignación (productos, margen, trazabilidad) en horizontal.
     * Las celdas llegan ya formateadas; filas con style=group ocupan todo el ancho, style=total va en negrita.
     *
     * @param list<array{label:string,align?:string}> $headers
     * @param list<array{cells:list<string>,style?:string}> $rows
     */
    public function generateConsignmentReport(string $title, string $subtitle, array $headers, array $rows, string $note = ''): string
    {
        $esc = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $companyName = $esc($this->company['company_name'] ?? 'Mi Empresa');
        $cols = max(1, count($headers));

        $head = '';
        foreach ($headers as $h) {
            $head .= '<th style="text-align:' . (($h['align'] ?? '') === 'right' ? 'right' : 'left') . ';">' . $esc($h['label']) . '</th>';
        }
        $body = '';
        foreach ($rows as $r) {
            $style = (string)($r['style'] ?? '');
            $cells = $r['cells'] ?? [];
            if ($style === 'group') {
                $body .= '<tr style="background:#E8F3EC;"><td colspan="' . $cols . '" style="font-weight:bold;color:#0D7C4A;padding-top:8px;">'
                    . $esc($cells[0] ?? '') . '</td></tr>';
                continue;
            }
            $trStyle = $style === 'total' ? ' style="font-weight:bold;background:#f5f5f5;"' : '';
            $body .= '<tr' . $trStyle . '>';
            foreach ($headers as $i => $h) {
                $align = ($h['align'] ?? '') === 'right' ? 'right' : 'left';
                $body .= '<td style="text-align:' . $align . ';border-bottom:1px solid #eee;">' . $esc($cells[$i] ?? '') . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . $cols . '" style="text-align:center;color:#666;padding:16px;">Sin registros para los filtros aplicados</td></tr>';
        }

        $html = $this->wrapHtml($esc($title), $companyName, '
            <p style="margin:0 0 10px;">' . $esc($subtitle) . '</p>
            <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:8px;">
                <tr style="background:#0D7C4A;color:#fff;">' . $head . '</tr>
                ' . $body . '
            </table>
            ' . ($note !== '' ? '<p style="margin:10px 0 0;font-size:9px;color:#666;">' . $esc($note) . '</p>' : '') . '
        ');
        return $this->generate($html, [0, 0, 841.89, 595.28]);
    }

    /**
     * Reporte PDF del listado de ventas (respeta filtros).
     * Incluye desglose de productos por cada venta.
     * @param list<array<string,mixed>> $sales cada venta puede traer clave `items`
     * @param array<string,mixed> $filters
     */
    /**
     * Resumen de ventas por medio de pago (SalesPeriodSummary::build).
     *
     * @param array<string,mixed> $summary
     */
    public function generateSalesPaymentSummary(array $summary): string
    {
        $currency = $this->currency['symbol'] ?? '$';
        $decimals = $this->currency['decimals'] ?? 0;
        $thousands = $this->currency['thousands'] ?? '.';
        $decimal = $this->currency['decimal'] ?? ',';
        $fmt = fn($n) => $currency . ' ' . number_format((float)$n, $decimals, $decimal, $thousands);
        $companyName = htmlspecialchars($this->company['company_name'] ?? 'Mi Empresa');

        $from = (string)($summary['from'] ?? '');
        $to = (string)($summary['to'] ?? '');
        $period = $from === $to
            ? date('d/m/Y', strtotime($from))
            : date('d/m/Y', strtotime($from)) . ' al ' . date('d/m/Y', strtotime($to));
        $total = (float)($summary['total'] ?? 0);

        $rows = '';
        foreach (($summary['by_method'] ?? []) as $pm) {
            $pct = $total > 0.009 ? round(((float)$pm['amount'] / $total) * 100, 1) : 0;
            $rows .= '<tr>
                <td>' . htmlspecialchars((string)$pm['label']) . '</td>
                <td style="text-align:center;">' . (int)$pm['count'] . '</td>
                <td style="text-align:right;">' . $fmt($pm['amount']) . '</td>
                <td style="text-align:right;">' . number_format($pct, 1, ',', '.') . '%</td>
            </tr>';
        }
        if ((float)($summary['pending'] ?? 0) > 0.009) {
            $pct = $total > 0.009 ? round(((float)$summary['pending'] / $total) * 100, 1) : 0;
            $rows .= '<tr style="color:#B45309;">
                <td>Por cobrar (crédito)</td>
                <td style="text-align:center;">—</td>
                <td style="text-align:right;">' . $fmt($summary['pending']) . '</td>
                <td style="text-align:right;">' . number_format($pct, 1, ',', '.') . '%</td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="text-align:center;color:#666;padding:16px;">Sin ventas en el periodo</td></tr>';
        }

        $discountLine = (float)($summary['discount'] ?? 0) > 0.009
            ? ' &nbsp;|&nbsp; <strong>Descuentos:</strong> ' . $fmt($summary['discount'])
            : '';

        $profitHtml = '';
        $profit = $summary['profit'] ?? null;
        if (is_array($profit)) {
            $pv = (float)$profit['profit'];
            $pColor = $pv < 0 ? '#B91C1C' : '#0D7C4A';
            $noCost = (int)($profit['items_without_cost'] ?? 0);
            $profitHtml = '
            <h3 style="margin:22px 0 8px;font-size:13px;color:#0D7C4A;">Utilidad del periodo</h3>
            <table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr><td>Ventas netas (sin domicilio)</td><td style="text-align:right;">' . $fmt($profit['revenue']) . '</td></tr>
                <tr><td>− Costo de ventas</td><td style="text-align:right;">' . $fmt($profit['cost']) . '</td></tr>
                <tr style="border-top:2px solid ' . $pColor . ';font-weight:700;color:' . $pColor . ';">
                    <td>Utilidad bruta</td>
                    <td style="text-align:right;">' . $fmt($pv) . ' (' . number_format((float)$profit['margin_pct'], 1, ',', '.') . '%)</td>
                </tr>
            </table>'
            . ($noCost > 0
                ? '<p style="margin-top:6px;font-size:9px;color:#B45309;">' . $noCost . ' línea(s) de producto sin costo registrado: la utilidad puede estar sobrestimada.</p>'
                : '');
        }

        $html = $this->wrapHtml('Resumen de ventas por medio de pago', $companyName, '
            <p style="margin:0 0 6px;color:#555;font-size:11px;">Periodo: <strong>' . htmlspecialchars($period) . '</strong> · Excluye ventas anuladas</p>
            <p style="margin:0 0 14px;"><strong>Ventas:</strong> ' . (int)($summary['count'] ?? 0)
            . ' &nbsp;|&nbsp; <strong>Total facturado:</strong> ' . $fmt($total) . $discountLine . '</p>
            <table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse;font-size:11px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Medio de pago</th>
                    <th style="text-align:center;">Ventas</th>
                    <th style="text-align:right;">Monto</th>
                    <th style="text-align:right;">%</th>
                </tr>
                ' . $rows . '
                <tr style="border-top:2px solid #0D7C4A;font-weight:700;">
                    <td>Total facturado</td>
                    <td style="text-align:center;">' . (int)($summary['count'] ?? 0) . '</td>
                    <td style="text-align:right;">' . $fmt($total) . '</td>
                    <td style="text-align:right;">100%</td>
                </tr>
            </table>
            <p style="margin-top:10px;font-size:9px;color:#888;">Una venta con pago mixto cuenta en cada medio usado.</p>
            ' . $profitHtml . '
        ');
        return $this->generate($html, 'A4');
    }

    /**
     * @param array{with_items?:bool,truncated?:bool,items_max?:int} $opts
     */
    public function generateSalesListReport(array $sales, array $filters = [], ?\PDO $db = null, array $opts = []): string
    {
        $withItems = (bool)($opts['with_items'] ?? true);
        $truncated = (bool)($opts['truncated'] ?? false);
        $labelCache = [];
        $payLabel = static function (string $m) use (&$labelCache, $db): string {
            if (!isset($labelCache[$m])) {
                $labelCache[$m] = PaymentMethodCatalog::label($m, $db);
            }
            return $labelCache[$m];
        };
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

        $tableOpen = '<table width="100%" cellpadding="' . ($withItems ? 6 : 4) . '" cellspacing="0" style="border-collapse:collapse;font-size:' . ($withItems ? 10 : 9) . 'px;">
                <tr style="background:#0D7C4A;color:#fff;">
                    <th style="text-align:left;">Factura</th>
                    <th style="text-align:left;">Cliente</th>
                    <th style="text-align:left;">Fecha</th>
                    <th style="text-align:right;">Total</th>
                    <th style="text-align:right;">Pagado</th>
                    <th style="text-align:left;">Método</th>
                    <th style="text-align:left;">Estado</th>
                </tr>';
        // Tablas cortas: una sola tabla gigante vuelve a Dompdf extremadamente lento.
        $salesPerTable = $withItems ? 25 : 60;

        $rowsHtml = '';
        $sumTotal = 0.0;
        $sumPaid = 0.0;
        $itemLines = 0;
        $n = 0;
        foreach ($sales as $s) {
            if ($n > 0 && $n % $salesPerTable === 0) {
                $rowsHtml .= '</table>' . $tableOpen;
            }
            $n++;
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
            $rowsHtml .= '<tr style="background:#eef6f1;font-weight:600;">
                <td>' . htmlspecialchars((string)($s['invoice_number'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($s['customer_name'] ?? 'General')) . '</td>
                <td>' . (!empty($s['sale_date']) ? date('d/m/Y H:i', strtotime((string)$s['sale_date'])) : '') . '</td>
                <td style="text-align:right;">' . $fmt($total) . '</td>
                <td style="text-align:right;">' . $fmt($paid) . '</td>
                <td>' . htmlspecialchars($payLabel((string)($s['payment_method'] ?? 'cash'))) . '</td>
                <td>' . htmlspecialchars($status) . '</td>
            </tr>';

            if (!$withItems) {
                continue;
            }
            $items = is_array($s['items'] ?? null) ? $s['items'] : [];
            if ($items === []) {
                $rowsHtml .= '<tr>
                    <td colspan="7" style="padding-left:18px;color:#888;font-size:9px;">Sin ítems registrados</td>
                </tr>';
                continue;
            }
            $rowsHtml .= '<tr style="background:#f7f7f7;color:#555;font-size:9px;">
                <td style="padding-left:18px;" colspan="2">Producto / SKU / EAN</td>
                <td style="text-align:right;">Cant.</td>
                <td style="text-align:right;">P. unit.</td>
                <td style="text-align:right;">Subtotal</td>
                <td colspan="2"></td>
            </tr>';
            foreach ($items as $it) {
                $itemLines++;
                $sku = trim((string)($it['product_code'] ?? ''));
                $ean = trim((string)($it['barcode'] ?? ''));
                $pname = trim((string)($it['product_name'] ?? ''));
                $label = $pname !== '' ? $pname : 'Producto';
                $codes = [];
                if ($sku !== '') {
                    $codes[] = 'SKU ' . $sku;
                }
                if ($ean !== '') {
                    $codes[] = 'EAN ' . $ean;
                }
                if ($codes !== []) {
                    $label .= ' · ' . implode(' · ', $codes);
                }
                $rowsHtml .= '<tr style="font-size:9px;">
                    <td colspan="2" style="padding-left:18px;">' . htmlspecialchars($label) . '</td>
                    <td style="text-align:right;">' . (float)($it['quantity'] ?? 0) . '</td>
                    <td style="text-align:right;">' . $fmt($it['unit_price'] ?? 0) . '</td>
                    <td style="text-align:right;">' . $fmt($it['subtotal'] ?? 0) . '</td>
                    <td colspan="2"></td>
                </tr>';
            }
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="7" style="text-align:center;color:#666;padding:16px;">Sin ventas con estos filtros</td></tr>';
        }

        $notes = '';
        if ($truncated) {
            $notes .= '<p style="margin:0 0 8px;padding:6px 10px;background:#FEF3C7;color:#92400E;font-size:10px;">'
                . 'Se muestran solo las primeras ' . count($sales) . ' ventas. Filtre por fechas o use «Exportar CSV» para el listado completo.</p>';
        }
        if (!$withItems && $sales !== []) {
            $notes .= '<p style="margin:0 0 8px;padding:6px 10px;background:#EFF6FF;color:#1E40AF;font-size:10px;">'
                . 'Con más de ' . (int)($opts['items_max'] ?? 250) . ' ventas el PDF no incluye el detalle de productos. Filtre un rango menor para verlo o use «Exportar CSV».</p>';
        }

        $html = $this->wrapHtml('Reporte de ventas', $companyName, '
            <p style="margin:0 0 12px;color:#555;font-size:11px;">' . $filterLine . '</p>
            ' . $notes . '
            <p style="margin:0 0 14px;"><strong>Ventas:</strong> ' . count($sales)
            . ($withItems ? ' &nbsp;|&nbsp; <strong>Líneas de producto:</strong> ' . $itemLines : '')
            . ' &nbsp;|&nbsp; <strong>Total:</strong> ' . $fmt($sumTotal)
            . ' &nbsp;|&nbsp; <strong>Pagado:</strong> ' . $fmt($sumPaid) . '</p>
            ' . $tableOpen . $rowsHtml . '</table>
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
                <td>' . htmlspecialchars((string)($r['barcode'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($r['name'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($r['location'] ?? '—')) . '</td>
                <td style="text-align:right;">' . (int)($r['stock'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($r['purchase_price'] ?? 0) . '</td>
                <td style="text-align:right;">' . $fmt($r['stock_value'] ?? 0) . '</td>
            </tr>';
        }
        if ($detailHtml === '') {
            $detailHtml = '<tr><td colspan="8" style="text-align:center;color:#666;">Sin detalle</td></tr>';
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
                    <th style="text-align:left;">EAN</th>
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
            'loan' => 'Préstamo (salida)',
            'loan_return' => 'Devolución préstamo (entrada)',
            'loan_received' => 'Préstamo recibido (entrada)',
            'loan_received_return' => 'Devolución préstamo recibido (salida)',
            'loan_received_settle' => 'Cierre préstamo recibido (sin stock)',
            'status_change' => 'Cambio de estado',
            'price_change' => 'Cambio de precio',
            'csv_import' => 'Importación CSV',
            'csv_export' => 'Exportación CSV',
            'woocommerce' => 'WooCommerce',
            'mercadolibre' => 'Mercado Libre',
            'purchase_cancel' => 'Cancel. compra',
            'sale_credit_note' => 'Nota crédito venta',
            'purchase_credit_note' => 'Nota crédito compra',
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
            $dateLabel = StockService::formatMovementDisplayAt($m);
            $rowsHtml .= '<tr>
                <td>' . htmlspecialchars($dateLabel !== '—' ? $dateLabel : '') . '</td>
                <td>' . htmlspecialchars((string)($m['product_code'] ?? ''))
                . (!empty($m['product_barcode'])
                    ? '<br><span style="color:#666;font-size:7px;">EAN: ' . htmlspecialchars((string)$m['product_barcode']) . '</span>'
                    : '')
                . '</td>
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
            $payRows .= '<tr><td>' . htmlspecialchars((string)($pm['label'] ?? ($payNames[$pm['payment_method']] ?? $pm['payment_method'])))
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