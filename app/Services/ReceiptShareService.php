<?php

namespace SoftNova\Services;

/**
 * Recibo compartible: texto WhatsApp + correo.
 */
class ReceiptShareService
{
    public function __construct(private \PDO $db, private array $company = [], private array $currency = [])
    {
    }

    public function saleSummary(int $saleId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, c.name customer_name, c.phone customer_phone, c.email customer_email,
                    c.document_number customer_document, u.name user_name
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.id = ?"
        );
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }
        $items = $this->db->prepare("SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id ASC");
        $items->execute([$saleId]);
        $sale['items'] = $items->fetchAll();
        $pays = $this->db->prepare("SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id ASC");
        $pays->execute([$saleId]);
        $sale['payments'] = $pays->fetchAll();
        return $sale;
    }

    public function buildText(array $sale): string
    {
        $sym = $this->currency['symbol'] ?? '$';
        $dec = (int)($this->currency['decimals'] ?? 0);
        $company = $this->company['company_name'] ?? 'Mi Empresa';
        $doc = SalesDocumentService::label((string)($sale['document_type'] ?? 'invoice'));
        $lines = [];
        $lines[] = $company;
        $lines[] = $doc . ' ' . ($sale['invoice_number'] ?? '');
        $lines[] = 'Fecha: ' . date('d/m/Y', strtotime((string)$sale['sale_date']));
        if (!empty($sale['due_date'])) {
            $lines[] = 'Vence: ' . date('d/m/Y', strtotime((string)$sale['due_date']));
        }
        $lines[] = 'Cliente: ' . ($sale['customer_name'] ?? 'Cliente Final');
        $delivery = max(0.0, (float)($sale['delivery_fee'] ?? 0));
        if ($delivery > 0.009) {
            $lines[] = 'Domicilio: ' . $sym . ' ' . number_format($delivery, $dec, ',', '.');
        }
        $lines[] = 'Total: ' . $sym . ' ' . number_format((float)$sale['total'], $dec, ',', '.');
        $lines[] = 'Estado: ' . ($sale['payment_status'] ?? '');
        if (!empty($sale['items'])) {
            $lines[] = '---';
            foreach (array_slice($sale['items'], 0, 8) as $it) {
                $lines[] = ((int)$it['quantity']) . ' x ' . $it['product_name'];
            }
        }
        $lines[] = 'Gracias por su compra.';
        return implode("\n", $lines);
    }

    public function whatsappUrl(array $sale, ?string $phone = null, ?string $ticketPdfUrl = null): string
    {
        $phone = preg_replace('/\D+/', '', (string)($phone ?: ($sale['customer_phone'] ?? '')));
        // Colombia: si viene 10 dígitos, anteponer 57
        if (strlen($phone) === 10) {
            $phone = '57' . $phone;
        }
        $text = $this->buildText($sale);
        if ($ticketPdfUrl) {
            $text .= "\n\nTicket 58mm (PDF):\n" . $ticketPdfUrl;
        } else {
            $text .= "\n\nAdjunto/enlace del ticket 58mm en PDF.";
        }
        $encoded = rawurlencode($text);
        if ($phone !== '') {
            return 'https://wa.me/' . $phone . '?text=' . $encoded;
        }
        return 'https://wa.me/?text=' . $encoded;
    }

    /** URL pública del ticket 58mm para compartir. */
    public function ticketPdfUrl(int $saleId): string
    {
        return \SoftNova\Core\base_url('/app/ventas?action=pdf&format=ticket&id=' . $saleId);
    }

    /** @return array{success:bool,message:string} */
    public function sendEmail(array $sale, string $toEmail): array
    {
        $mail = new MailService($this->company);
        $doc = SalesDocumentService::label((string)($sale['document_type'] ?? 'invoice'));
        $subject = $doc . ' ' . ($sale['invoice_number'] ?? '');
        $body = $this->buildText($sale) . "\n\nAdjunto encontrará el ticket (58mm) en PDF.";

        $temporal = null;
        try {
            $pdf = new PdfService($this->company, $this->currency);
            $content = $pdf->generateInvoice(
                $sale,
                $sale['items'] ?? [],
                $sale['payments'] ?? [],
                'ticket'
            );
            $inv = preg_replace('/[^\w\-]+/', '_', (string)($sale['invoice_number'] ?? ($sale['id'] ?? 'venta')));
            $nombre = 'Ticket_' . $inv . '.pdf';
            $temporal = tempnam(sys_get_temp_dir(), 'ticket-') . '.pdf';
            if ($temporal === false || file_put_contents($temporal, $content) === false) {
                return ['success' => false, 'message' => 'No se pudo generar el PDF del ticket'];
            }
            return $mail->send($toEmail, $subject, $body, $temporal, $nombre);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo enviar el correo: ' . $e->getMessage()];
        } finally {
            if ($temporal !== null && is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }
}
