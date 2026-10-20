<?php

declare(strict_types=1);

namespace SoftNova\Services;

/**
 * Escritura mínima de .xlsx (Office Open XML) sin dependencias externas.
 * Soporta texto, número, cantidad, porcentaje y moneda (formato de celda Excel).
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
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene ext-zip para generar Excel (.xlsx)');
        }

        $filename = preg_replace('/[^\w.\-]+/u', '_', $filename) ?: 'export.xlsx';
        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename .= '.xlsx';
        }

        $binary = self::build($headers, $rows, $columnTypes);

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
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene ext-zip para generar Excel (.xlsx)');
        }

        $sheetXml = self::sheetXml($headers, $rows, $columnTypes);
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

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $binary = (string)file_get_contents($zipPath);
        @unlink($zipPath);

        return $binary;
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

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        for ($c = 0; $c < $colCount; $c++) {
            $width = match ($columnTypes[$c] ?? '') {
                'currency' => 14,
                'percent', 'qty', 'number' => 11,
                default => ($c === 3 || $c === 4) ? 28 : 14,
            };
            $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        // Encabezado
        $xml .= '<row r="1">';
        foreach ($headers as $c => $label) {
            $xml .= self::cellXml(self::colLetter((int)$c) . '1', (string)$label, 'text', true);
        }
        $xml .= '</row>';

        $r = 2;
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

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Factura" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
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
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0 general
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 1 header
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' // 2 currency
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' // 3 percent-like
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' // 4 qty
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' // 5 number
            . '</cellXfs>'
            . '</styleSheet>';
    }
}
