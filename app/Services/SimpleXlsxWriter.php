<?php

declare(strict_types=1);

namespace SoftNova\Services;

/**
 * Escritura mínima de .xlsx (Office Open XML) sin dependencias externas.
 * Soporta texto, número, cantidad, porcentaje y moneda (formato de celda Excel).
 * Una hoja (download/build) o varias (downloadSheets/buildSheets).
 */
final class SimpleXlsxWriter
{
    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows Cada celda: escalar, null, o ['v'=>mixed,'t'=>'text|number|currency|percent|qty']
     * @param array<int,string> $columnTypes Tipo por índice de columna (0-based); se usa si la celda no define 't'
     */
    public static function download(string $filename, array $headers, array $rows, array $columnTypes = []): void
    {
        self::downloadSheets($filename, [[
            'name' => 'Datos',
            'headers' => $headers,
            'rows' => $rows,
            'columnTypes' => $columnTypes,
        ]]);
    }

    /**
     * @param list<array{name?:string,headers:list<string>,rows?:list<list<mixed>>,columnTypes?:array<int,string>}> $sheets
     */
    public static function downloadSheets(string $filename, array $sheets): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene ext-zip para generar Excel (.xlsx)');
        }

        $filename = preg_replace('/[^\w.\-]+/u', '_', $filename) ?: 'export.xlsx';
        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename .= '.xlsx';
        }

        $binary = self::buildSheets($sheets);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . (string)strlen($binary));
        echo $binary;
        exit;
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param array<int,string> $columnTypes
     */
    public static function build(array $headers, array $rows = [], array $columnTypes = []): string
    {
        return self::buildSheets([[
            'name' => 'Datos',
            'headers' => $headers,
            'rows' => $rows,
            'columnTypes' => $columnTypes,
        ]]);
    }

    /**
     * @param list<array{name?:string,headers:list<string>,rows?:list<list<mixed>>,columnTypes?:array<int,string>}> $sheets
     */
    public static function buildSheets(array $sheets): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene ext-zip para generar Excel (.xlsx)');
        }
        if ($sheets === []) {
            throw new \InvalidArgumentException('Excel sin hojas');
        }

        $normalized = [];
        $usedNames = [];
        foreach ($sheets as $i => $sheet) {
            $name = self::sanitizeSheetName((string)($sheet['name'] ?? ('Hoja' . ($i + 1))), $usedNames);
            $usedNames[mb_strtolower($name)] = true;
            $normalized[] = [
                'name' => $name,
                'headers' => array_values($sheet['headers'] ?? []),
                'rows' => array_values($sheet['rows'] ?? []),
                'columnTypes' => $sheet['columnTypes'] ?? [],
            ];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para Excel');
        }
        $zipPath = $tmp . '.xlsx';
        @unlink($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo Excel');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml(count($normalized)));
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($normalized));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml(count($normalized)));
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        foreach ($normalized as $i => $sheet) {
            $n = $i + 1;
            $zip->addFromString(
                'xl/worksheets/sheet' . $n . '.xml',
                self::sheetXml($sheet['headers'], $sheet['rows'], $sheet['columnTypes'])
            );
        }
        $zip->close();

        $binary = (string)file_get_contents($zipPath);
        @unlink($zipPath);

        return $binary;
    }

    /**
     * @param array<string,bool> $usedLower
     */
    private static function sanitizeSheetName(string $name, array $usedLower): string
    {
        $name = trim(preg_replace('/[\\\\\/\?\*\[\]\:]/u', ' ', $name) ?? '');
        if ($name === '') {
            $name = 'Hoja';
        }
        if (mb_strlen($name) > 31) {
            $name = mb_substr($name, 0, 31);
        }
        $base = $name;
        $n = 2;
        while (isset($usedLower[mb_strtolower($name)])) {
            $suffix = ' (' . $n . ')';
            $name = mb_substr($base, 0, max(1, 31 - mb_strlen($suffix))) . $suffix;
            $n++;
        }
        return $name;
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param array<int,string> $columnTypes
     */
    private static function sheetXml(array $headers, array $rows, array $columnTypes): string
    {
        $colCount = max(1, count($headers));
        foreach ($rows as $row) {
            $colCount = max($colCount, count($row));
        }

        $freeze = $headers !== [];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($freeze) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        $xml .= '<cols>';
        for ($c = 0; $c < $colCount; $c++) {
            $width = match ($columnTypes[$c] ?? '') {
                'currency' => 14,
                'percent', 'qty', 'number' => 11,
                default => ($c === 3 || $c === 4) ? 28 : 16,
            };
            $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        $r = 1;
        if ($headers !== []) {
            $xml .= '<row r="1">';
            foreach ($headers as $c => $label) {
                $xml .= self::cellXml(self::colLetter((int)$c) . '1', (string)$label, 'text', true);
            }
            $xml .= '</row>';
            $r = 2;
        }

        foreach ($rows as $row) {
            $xml .= '<row r="' . $r . '">';
            for ($c = 0; $c < $colCount; $c++) {
                $raw = $row[$c] ?? null;
                [$value, $type] = self::normalizeCell($raw, $columnTypes[$c] ?? 'text');
                if ($value === null || $value === '') {
                    continue;
                }
                $xml .= self::cellXml(self::colLetter($c) . $r, $value, $type, false);
            }
            $xml .= '</row>';
            $r++;
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /**
     * @return array{0:mixed,1:string}
     */
    private static function normalizeCell(mixed $raw, string $defaultType): array
    {
        if (is_array($raw)) {
            $value = $raw['v'] ?? null;
            $type = (string)($raw['t'] ?? $defaultType);
            return [$value, $type];
        }
        return [$raw, $defaultType];
    }

    private static function cellXml(string $ref, mixed $value, string $type, bool $header): string
    {
        if ($header) {
            return '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . self::xml((string)$value) . '</t></is></c>';
        }

        if (in_array($type, ['currency', 'percent', 'qty', 'number'], true) && !is_numeric($value)) {
            return '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::xml((string)$value) . '</t></is></c>';
        }

        return match ($type) {
            'currency' => '<c r="' . $ref . '" s="2"><v>' . self::num($value) . '</v></c>',
            'percent' => '<c r="' . $ref . '" s="3"><v>' . self::num($value) . '</v></c>',
            'qty' => '<c r="' . $ref . '" s="4"><v>' . self::num($value) . '</v></c>',
            'number' => '<c r="' . $ref . '" s="5"><v>' . self::num($value) . '</v></c>',
            default => '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::xml((string)$value) . '</t></is></c>',
        };
    }

    private static function num(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '0';
        }
        return rtrim(rtrim(sprintf('%.6F', (float)$value), '0'), '.') ?: '0';
    }

    private static function colLetter(int $index): string
    {
        $index = max(0, $index);
        $letter = '';
        do {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $letter;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypesXml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $xml . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param list<array{name:string}> $sheets
     */
    private static function workbookXml(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $xml .= '<sheet name="' . self::xml($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        }
        return $xml . '</sheets></workbook>';
    }

    private static function workbookRelsXml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . ($sheetCount + 1) . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>';
        return $xml . '</Relationships>';
    }

    private static function stylesXml(): string
    {
        // numFmtId 164+ = formatos personalizados. s="2" moneda, s="3" %, s="4" qty, s="5" número.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3">'
            . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="0.00"/>'
            . '<numFmt numFmtId="166" formatCode="0.000"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }
}
