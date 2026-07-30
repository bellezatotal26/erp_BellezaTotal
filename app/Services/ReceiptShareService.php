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
            "SELECT s.*, c.name customer_name, c.phone customer_phone, c.email customer_email
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?"
        );
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }
        $items = $this->db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $items->execute([$saleId]);
        $sale['items'] = $items->fetchAll();
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
        $lines[] = 'Cliente: ' . ($sale['customer_name'] ?? 'Consumidor final');
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

    public function whatsappUrl(array $sale, ?string $phone = null): string
    {
        $phone = preg_replace('/\D+/', '', (string)($phone ?: ($sale['customer_phone'] ?? '')));
        // Colombia: si viene 10 dígitos, anteponer 57
        if (strlen($phone) === 10) {
            $phone = '57' . $phone;
        }
        $text = rawurlencode($this->buildText($sale));
        if ($phone !== '') {
            return 'https://wa.me/' . $phone . '?text=' . $text;
        }
        return 'https://wa.me/?text=' . $text;
    }

    /** @return array{success:bool,message:string} */
    public function sendEmail(array $sale, string $toEmail): array
    {
        $mail = new MailService($this->company);
        $doc = SalesDocumentService::label((string)($sale['document_type'] ?? 'invoice'));
        $subject = $doc . ' ' . ($sale['invoice_number'] ?? '');
        return $mail->send($toEmail, $subject, $this->buildText($sale));
    }
}
