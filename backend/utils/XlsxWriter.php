<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Minimal .xlsx writer (Phase 9.2) using only ZipArchive, no Composer
 * packages. One or more sheets of plain text cells, a bold frozen header row
 * and auto-ish column widths.
 *
 * Every cell is written as an inline STRING (t="inlineStr"), so Excel never
 * evaluates a value as a formula: "=SUM(...)" in a name stays literal text
 * (no formula injection, unlike CSV).
 *
 * Sheet options (addSheet's 4th argument, all optional):
 *   highlightColumns  0-based columns shown in red: header = white bold text
 *                     on dark red (C00000), the whole data column = dark red
 *                     text on light red (FFC7CE), also for rows typed later.
 *   textColumns       0-based columns formatted as Text ("@"), so values such
 *                     as Guest No. 001 keep their leading zeros in Excel.
 *   blankRows         number of empty rows below the data that get the column
 *                     styles as cells too (for apps that ignore column styles).
 */
final class XlsxWriter
{
    /** cellXfs indexes in styles.xml */
    public const STYLE_HEADER = 1;
    public const STYLE_HIGHLIGHT_HEADER = 2;
    public const STYLE_HIGHLIGHT = 3;
    public const STYLE_TEXT = 4;
    public const STYLE_HIGHLIGHT_TEXT = 5;
    /** Fill colours (ARGB) of highlighted columns. */
    public const HIGHLIGHT_HEADER_FILL = 'FFC00000';
    public const HIGHLIGHT_FILL = 'FFFFC7CE';

    /** @var list<array{name: string, headers: list<string>, rows: list<list<mixed>>, options: array<string, mixed>}> */
    private array $sheets = [];

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param array{highlightColumns?: list<int>, textColumns?: list<int>, blankRows?: int} $options
     */
    public function addSheet(string $name, array $headers, array $rows, array $options = []): self
    {
        // Excel sheet names: max 31 chars, no []:*?/\
        $name = mb_substr(trim((string) preg_replace('#[\[\]:*?/\\\\]#', ' ', $name)), 0, 31) ?: 'Sheet' . (count($this->sheets) + 1);
        $this->sheets[] = ['name' => $name, 'headers' => $headers, 'rows' => $rows, 'options' => $options];

        return $this;
    }

    /** @return string the .xlsx file contents */
    public function toString(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Sheet1', [], []);
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Excel file.');
        }

        $count = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', self::contentTypes($count));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels($count));
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><sz val="11"/><color rgb="FF9C0006"/><name val="Calibri"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . self::HIGHLIGHT_HEADER_FILL . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . self::HIGHLIGHT_FILL . '"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="3" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', self::sheetXml($sheet['headers'], $sheet['rows'], $sheet['options']));
        }
        $zip->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    private static function contentTypes(int $count): string
    {
        $sheets = '';
        for ($i = 1; $i <= $count; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="' . self::esc($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private static function workbookRels(int $count): string
    {
        $rels = '';
        for ($i = 1; $i <= $count; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param array<string, mixed> $options
     */
    private static function sheetXml(array $headers, array $rows, array $options = []): string
    {
        $highlight = array_map('intval', (array) ($options['highlightColumns'] ?? []));
        $text = array_map('intval', (array) ($options['textColumns'] ?? []));
        $blankRows = max(0, min(5000, (int) ($options['blankRows'] ?? 0)));
        // Style of the data cells of each column (0 = default).
        $columnStyle = static function (int $i) use ($highlight, $text): int {
            $h = in_array($i, $highlight, true);
            $t = in_array($i, $text, true);

            return $h && $t ? self::STYLE_HIGHLIGHT_TEXT : ($h ? self::STYLE_HIGHLIGHT : ($t ? self::STYLE_TEXT : 0));
        };
        $widths = array_map(static fn (string $h): int => mb_strlen($h), $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $value) {
                $widths[$i] = max($widths[$i] ?? 0, min(60, mb_strlen(self::text($value))));
            }
        }
        $cols = '';
        foreach ($widths as $i => $width) {
            $style = $columnStyle($i);
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(8, $width + 2) . '"'
                . ($style ? ' style="' . $style . '"' : '') . ' customWidth="1"/>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . ($headers !== [] ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
            . '</sheetView></sheetViews>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>';
        $rowNumber = 1;
        if ($headers !== []) {
            $xml .= self::rowXml($rowNumber++, $headers, static fn (int $i): int => in_array($i, $highlight, true) ? self::STYLE_HIGHLIGHT_HEADER : self::STYLE_HEADER);
        }
        foreach ($rows as $row) {
            $xml .= self::rowXml($rowNumber++, array_values($row), $columnStyle);
        }
        $styled = array_values(array_filter(range(0, max(0, count($widths) - 1)), static fn (int $i): bool => $columnStyle($i) !== 0));
        for ($n = 0; $n < $blankRows && $styled !== []; $n++) {
            $xml .= '<row r="' . $rowNumber . '">';
            foreach ($styled as $i) {
                $xml .= '<c r="' . self::column($i) . $rowNumber . '" s="' . $columnStyle($i) . '"/>';
            }
            $xml .= '</row>';
            $rowNumber++;
        }

        return $xml . '</sheetData></worksheet>';
    }

    /**
     * @param list<mixed> $cells
     * @param callable(int): int $styleOf column index => cellXfs index (0 = none)
     */
    private static function rowXml(int $rowNumber, array $cells, callable $styleOf): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach ($cells as $i => $value) {
            $text = self::text($value);
            $style = $styleOf($i);
            $ref = self::column($i) . $rowNumber;
            if ($text === '') {
                if ($style > self::STYLE_HEADER) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"/>'; // keep the highlight on empty cells
                }
                continue;
            }
            $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">'
                . self::esc($text) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    private static function text(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        // Strip characters that are not allowed in XML 1.0.
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
