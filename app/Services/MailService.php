<?php

namespace SoftNova\Services;

/**
 * Envío simple de correo (mail nativo de PHP / cPanel).
 * No requiere Composer; en hosting compartido suele funcionar si el servidor tiene mail().
 */
class MailService
{
    public function __construct(private array $company = [])
    {
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function send(string $to, string $subject, string $bodyText, ?string $attachmentPath = null, ?string $attachmentName = null): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Correo destino inválido'];
        }

        $fromName = (string)($this->company['company_name'] ?? 'Seri ERP');
        $fromEmail = (string)($this->company['company_email'] ?? '');
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        $boundary = '=_Seri_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $bodyText . "\r\n\r\n";

        if ($attachmentPath && is_file($attachmentPath)) {
            $name = $attachmentName ?: basename($attachmentPath);
            $data = chunk_split(base64_encode((string)file_get_contents($attachmentPath)));
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: application/pdf; name=\"{$name}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n";
            $message .= $data . "\r\n";
        }
        $message .= "--{$boundary}--\r\n";

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers));
        if (!$ok) {
            return ['success' => false, 'message' => 'No se pudo enviar el correo (verifique mail() en el hosting)'];
        }
        return ['success' => true, 'message' => 'Correo enviado a ' . $to];
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
