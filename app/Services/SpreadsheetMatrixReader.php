<?php

namespace SoftNova\Services;

/**
 * Lectura genérica de extractos y reportes (CSV, TXT, XLSX, XLS-HTML) como matriz de texto,
 * detección de encabezados por sinónimos y conversión de montos / fechas en formato colombiano.
 */
class SpreadsheetMatrixReader
{
    public const MAX_ROWS = 20000;

    /** @return list<list<string>> */
    public static function read(string $path, string $originalName): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('No se pudo leer el archivo subido');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $raw = (string)file_get_contents($path, false, null, 0, 4);
        $isZip = strncmp($raw, "PK\x03\x04", 4) === 0;

        if ($isZip || in_array($ext, ['xlsx', 'xlsm'], true)) {
            if (!$isZip) {
                throw new \RuntimeException('El archivo no es un Excel .xlsx válido. Guárdelo de nuevo como .xlsx o CSV.');
            }
            $matrix = self::readXlsx($path);
        } else {
            $content = (string)file_get_contents($path);
            if (strncmp($content, "\xD0\xCF\x11\xE0", 4) === 0) {
                throw new \RuntimeException(
                    'El formato .xls antiguo (binario) no es compatible. Ábralo en Excel y guárdelo como .xlsx o CSV.'
                );
            }
            $content = self::toUtf8($content);
            $matrix = stripos(substr($content, 0, 5000), '<table') !== false
                ? self::htmlToMatrix($content)
                : self::csvToMatrix($content);
        }

        $out = [];
        foreach ($matrix as $row) {
            $row = array_map(static fn ($v) => trim(str_replace("\xC2\xA0", ' ', (string)$v)), $row);
            while ($row !== [] && end($row) === '') {
                array_pop($row);
            }
            if ($row === [] || implode('', $row) === '') {
                continue;
            }
            $out[] = $row;
            if (count($out) >= self::MAX_ROWS) {
                break;
            }
        }
        if ($out === []) {
            throw new \RuntimeException('El archivo no tiene filas con datos');
        }
        return $out;
    }

    /**
     * Busca la fila de encabezados: la que más campos reconoce entre las primeras 40.
     *
     * @param list<list<string>> $matrix
     * @param array<string,list<string>> $synonyms campo => sinónimos normalizados (sin tildes, minúsculas);
     *        con prefijo "=" solo valen como coincidencia exacta. El orden de los campos define la prioridad.
     * @return array{header:int,map:array<string,int>,labels:array<string,string>}|null
     */
    public static function detectHeader(array $matrix, array $synonyms, array $required): ?array
    {
        $best = null;
        $limit = min(40, count($matrix));
        for ($i = 0; $i < $limit; $i++) {
            $map = self::mapRow($matrix[$i], $synonyms);
            $hits = count($map);
            $hasRequired = !array_diff($required, array_keys($map));
            if ($hasRequired && ($best === null || $hits > $best['hits'])) {
                $best = ['header' => $i, 'map' => $map, 'hits' => $hits];
            }
        }
        if ($best === null) {
            return null;
        }
        $labels = [];
        foreach ($best['map'] as $field => $col) {
            $labels[$field] = (string)($matrix[$best['header']][$col] ?? '');
        }
        return ['header' => $best['header'], 'map' => $best['map'], 'labels' => $labels];
    }

    /**
     * @param list<string> $row
     * @param array<string,list<string>> $synonyms
     * @return array<string,int>
     */
    private static function mapRow(array $row, array $synonyms): array
    {
        $map = [];
        $used = [];
        // Primero coincidencias exactas, luego parciales: evita que "valor" se lleve "valor neto".
        foreach ([true, false] as $exact) {
            foreach ($synonyms as $field => $words) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($row as $col => $cell) {
                    if (isset($used[$col])) {
                        continue;
                    }
                    $norm = self::normalize($cell);
                    if ($norm === '' || strlen($norm) > 60) {
                        continue;
                    }
                    foreach ($words as $w) {
                        $onlyExact = str_starts_with($w, '=');
                        if ($onlyExact) {
                            $w = substr($w, 1);
                        } elseif (!$exact && strlen($w) < 4) {
                            continue;
                        }
                        $match = ($exact || $onlyExact) ? $norm === $w : str_contains($norm, $w);
                        if ($match) {
                            $map[$field] = $col;
                            $used[$col] = true;
                            continue 3;
                        }
                    }
                }
            }
        }
        return $map;
    }

    /** Letra de columna (A, B, …, AA) → índice 0-based; -1 si no es válida. */
    public static function columnIndex(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        if ($letters === '') {
            return -1;
        }
        if (ctype_digit($letters)) {
            return max(-1, (int)$letters - 1);
        }
        if (!preg_match('/^[A-Z]{1,2}$/', $letters)) {
            return -1;
        }
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    public static function columnLetter(int $index): string
    {
        $index++;
        $s = '';
        while ($index > 0) {
            $m = ($index - 1) % 26;
            $s = chr(65 + $m) . $s;
            $index = intdiv($index - $m - 1, 26);
        }
        return $s;
    }

    public static function normalize(string $text): string
    {
        $text = strtr(trim($text), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ]);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9%]+/', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Monto en formato colombiano o internacional: "$ 1.234.567,89", "-4000.00", "(1.500)", "1,234.50".
     */
    public static function parseAmount(string $value): ?float
    {
        $v = trim(str_replace(["\xC2\xA0", ' ', '$', 'COP', 'cop', "'"], '', $value));
        if ($v === '' || $v === '-') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $negative = true;
            $v = $m[1];
        }
        if (str_starts_with($v, '-')) {
            $negative = !$negative;
            $v = substr($v, 1);
        } elseif (str_starts_with($v, '+')) {
            $v = substr($v, 1);
        }
        if (str_ends_with($v, '-')) {
            $negative = !$negative;
            $v = substr($v, 0, -1);
        }
        if ($v === '' || !preg_match('/^[0-9.,]+$/', $v)) {
            return null;
        }
        $lastDot = strrpos($v, '.');
        $lastComma = strrpos($v, ',');
        if ($lastDot !== false && $lastComma !== false) {
            if ($lastComma > $lastDot) {
                $v = str_replace(['.', ','], ['', '.'], $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($lastComma !== false) {
            $v = preg_match('/^\d{1,3}(,\d{3})+$/', $v) ? str_replace(',', '', $v) : str_replace(',', '.', $v);
        } elseif ($lastDot !== false) {
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
                $v = str_replace('.', '', $v);
            }
        }
        if (!is_numeric($v)) {
            return null;
        }
        $n = round((float)$v, 2);
        return $negative ? -$n : $n;
    }

    private const MONTHS = [
        'ene' => 1, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'apr' => 4, 'may' => 5,
        'jun' => 6, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10,
        'nov' => 11, 'dic' => 12, 'dec' => 12,
    ];

    /** Fecha → Y-m-d. Acepta AAAAMMDD, dd/mm/aaaa, aaaa-mm-dd, "15 ene 2026", serial de Excel y hora al final. */
    public static function parseDate(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
            $serial = (int)floor((float)$v);
            if ($serial > 20000 && $serial < 80000) {
                return gmdate('Y-m-d', ($serial - 25569) * 86400);
            }
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
            return self::ymd((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
            return self::ymd((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $v, $m)) {
            $y = (int)$m[3];
            if ($y < 100) {
                $y += 2000;
            }
            $a = (int)$m[1];
            $b = (int)$m[2];
            // Formato colombiano dd/mm; si el segundo número no puede ser mes, se asume mm/dd.
            return $b > 12 && $a <= 12 ? self::ymd($y, $a, $b) : self::ymd($y, $b, $a);
        }
        $norm = self::normalize($v);
        if (preg_match('/^(\d{1,2}) (?:de )?([a-z]{3})[a-z]* (?:de )?(\d{4})/', $norm, $m)
            && isset(self::MONTHS[$m[2]])) {
            return self::ymd((int)$m[3], self::MONTHS[$m[2]], (int)$m[1]);
        }
        if (preg_match('/^([a-z]{3})[a-z]* (\d{1,2}) (\d{4})/', $norm, $m) && isset(self::MONTHS[$m[1]])) {
            return self::ymd((int)$m[3], self::MONTHS[$m[1]], (int)$m[2]);
        }
        return null;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if ($y < 2000 || $y > 2100 || !checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    public static function toUtf8(string $raw): string
    {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            return substr($raw, 3);
        }
        if (strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0) {
            $enc = $raw[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
            $conv = function_exists('mb_convert_encoding')
                ? @mb_convert_encoding(substr($raw, 2), 'UTF-8', $enc)
                : @iconv($enc, 'UTF-8//IGNORE', substr($raw, 2));
            return is_string($conv) ? $conv : $raw;
        }
        if (preg_match('//u', $raw)) {
            return $raw;
        }
        $conv = function_exists('mb_convert_encoding')
            ? @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252')
            : @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
        return is_string($conv) ? $conv : $raw;
    }

    /** @return list<list<string>> */
    private static function csvToMatrix(string $content): array
    {
        $sample = substr($content, 0, 8000);
        $best = ',';
        $bestScore = -1;
        foreach ([';', ',', "\t", '|'] as $sep) {
            $lines = array_slice(preg_split('/\r\n|\n|\r/', $sample) ?: [], 0, 30);
            $counts = [];
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $counts[] = count(str_getcsv($line, $sep));
                }
            }
            if (!$counts) {
                continue;
            }
            $multi = count(array_filter($counts, static fn ($c) => $c > 1));
            $score = $multi * 10 + (int)round(array_sum($counts) / count($counts));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sep;
            }
        }
        $h = fopen('php://memory', 'r+');
        if (!$h) {
            return [];
        }
        fwrite($h, $content);
        rewind($h);
        $matrix = [];
        while (($row = fgetcsv($h, 0, $best, '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $matrix[] = array_map(static fn ($v) => (string)$v, $row);
            if (count($matrix) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($h);
        return $matrix;
    }

    /** @return list<list<string>> */
    private static function htmlToMatrix(string $html): array
    {
        $matrix = [];
        if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows)) {
            return [];
        }
        foreach ($rows[1] as $rowHtml) {
            if (!preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cells)) {
                continue;
            }
            $matrix[] = array_map(
                static fn ($c) => trim(html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                $cells[1]
            );
        }
        return $matrix;
    }

    /** Primera hoja con datos de un .xlsx (sharedStrings + inlineStr). @return list<list<string>> */
    private static function readXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'El servidor no tiene la extensión PHP Zip para leer Excel. Exporte el archivo a CSV e inténtelo de nuevo.'
            );
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo Excel (.xlsx)');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ss) && $ss !== '') {
            $xml = @simplexml_load_string(self::stripNs($ss));
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string)$si->t;
                        continue;
                    }
                    $parts = [];
                    foreach ($si->r as $r) {
                        $parts[] = (string)$r->t;
                    }
                    $shared[] = implode('', $parts);
                }
            }
        }
        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#i', $name, $m)) {
                $sheets[(int)$m[1]] = $name;
            }
        }
        ksort($sheets);
        $result = [];
        foreach ($sheets as $sheetName) {
            $sheetXml = $zip->getFromName($sheetName);
            if (!is_string($sheetXml) || $sheetXml === '') {
                continue;
            }
            $sheet = @simplexml_load_string(self::stripNs($sheetXml));
            if ($sheet === false || !isset($sheet->sheetData)) {
                continue;
            }
            $matrix = [];
            foreach ($sheet->sheetData->row as $row) {
                $cells = [];
                $max = -1;
                foreach ($row->c as $c) {
                    $ref = (string)($c['r'] ?? '');
                    $idx = preg_match('/^([A-Z]+)/i', $ref, $mm) ? self::columnIndex($mm[1]) : $max + 1;
                    $max = max($max, $idx);
                    $type = (string)($c['t'] ?? '');
                    if ($type === 's') {
                        $val = $shared[(int)($c->v ?? -1)] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $val = isset($c->is->t) ? (string)$c->is->t : '';
                        if ($val === '' && isset($c->is->r)) {
                            foreach ($c->is->r as $r) {
                                $val .= (string)$r->t;
                            }
                        }
                    } else {
                        $val = (string)($c->v ?? '');
                    }
                    $cells[$idx] = $val;
                }
                if ($max < 0) {
                    continue;
                }
                $line = array_fill(0, $max + 1, '');
                foreach ($cells as $k => $v) {
                    $line[$k] = $v;
                }
                $matrix[] = $line;
                if (count($matrix) > self::MAX_ROWS + 50) {
                    break;
                }
            }
            if (count($matrix) > count($result)) {
                $result = $matrix;
            }
            if (count($result) > 3) {
                break;
            }
        }
        $zip->close();
        return $result;
    }

    private static function stripNs(string $xml): string
    {
        $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/i', '', $xml) ?? $xml;
        return preg_replace('/(<\/?)[\w.]+:([\w.-]+)/', '$1$2', $xml) ?? $xml;
    }
}
