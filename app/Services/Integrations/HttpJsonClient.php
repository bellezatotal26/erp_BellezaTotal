<?php

namespace SoftNova\Services\Integrations;

/**
 * Cliente HTTP JSON compartido (cURL) con whitelist de hosts.
 */
class HttpJsonClient
{
    /**
     * @param list<string> $allowedHosts
     * @return array{ok:bool,status:int,data?:mixed,error?:string,raw?:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $jsonBody = null,
        ?array $formBody = null,
        int $timeout = 30,
        array $allowedHosts = []
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'error' => 'cURL no disponible en el servidor'];
        }

        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'Solo se permiten URLs HTTPS válidas'];
        }
        if ($allowedHosts) {
            $okHost = false;
            foreach ($allowedHosts as $allowed) {
                $allowed = strtolower(trim($allowed));
                if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                    $okHost = true;
                    break;
                }
            }
            if (!$okHost) {
                return ['ok' => false, 'status' => 0, 'error' => 'Host no permitido: ' . $host];
            }
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        } elseif ($formBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($formBody);
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'status' => $status, 'error' => 'cURL: ' . $error];
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400) {
            $message = is_array($decoded)
                ? (string)($decoded['message'] ?? $decoded['error'] ?? $decoded['error_description'] ?? ('HTTP ' . $status))
                : ('HTTP ' . $status);
            return ['ok' => false, 'status' => $status, 'error' => $message, 'data' => $decoded, 'raw' => (string)$raw];
        }

        return ['ok' => true, 'status' => $status, 'data' => $decoded, 'raw' => (string)$raw];
    }
}
