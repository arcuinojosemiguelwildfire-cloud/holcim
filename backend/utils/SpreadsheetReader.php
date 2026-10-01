<?php

declare(strict_types=1);

namespace App\Utils;

use App\Core\HttpException;

/**
 * Reads the first worksheet of a CSV or XLSX file into a header row plus data
 * rows, using only PHP built-ins (no Composer). Cell values are treated as
 * untrusted text: control characters are stripped and lengths are capped.
 *
 * XLS (legacy binary Excel) is not supported; users are asked to re-save the
 * file as XLSX or CSV.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 100;
    public const MAX_CELL_LENGTH = 1000;
    private const MAX_XML_BYTES = 50 * 1024 * 1024; // zip-bomb guard (uncompressed)

    /**
     * @return array{headers: list<string>, rows: list<array{rowNumber: int, cells: list<string>}>}
     */
    public static function read(string $path, string $type): array
    {
        $raw = match ($type) {
            'csv' => self::readCsv($path),
            'xlsx' => self::readXlsx($path),
            default => throw HttpException::badRequest('Unsupported file type.'),
        };

        return self::normalise($raw);
    }

    /**
     * Turns raw rows (row number => cells) into headers + data rows.
     * The first non-empty row is the header row. Empty rows are skipped.
     *
     * @param array<int, list<string>> $raw
     * @return array{headers: list<string>, rows: list<array{rowNumber: int, cells: list<string>}>}
     */
    private static function normalise(array $raw): array
    {
        $headerCells = null;
        $rows = [];

        foreach ($raw as $rowNumber => $cells) {
            $cells = array_map([self::class, 'cleanCell'], $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if ($headerCells === null) {
                $headerCells = $cells;
                continue;
            }
            $rows[] = ['rowNumber' => $rowNumber, 'cells' => $cells];
            if (count($rows) > self::MAX_ROWS) {
                throw HttpException::badRequest('The file has more than ' . self::MAX_ROWS . ' data rows. Split it into smaller files.');
            }
        }

        if ($headerCells === null) {
            throw HttpException::badRequest('The file is empty. The first row must contain column names.');
        }

        // Width = last non-empty header or widest row, capped.
        $width = 0;
        foreach ($headerCells as $index => $value) {
            if ($value !== '') {
                $width = $index + 1;
            }
        }
        foreach ($rows as $row) {
            for ($i = count($row['cells']) - 1; $i >= $width; $i--) {
                if ($row['cells'][$i] !== '') {
                    $width = $i + 1;
                    break;
                }
            }
        }
        if ($width > self::MAX_COLUMNS) {
            throw HttpException::badRequest('The file has more than ' . self::MAX_COLUMNS . ' columns.');
        }

        $headers = [];
        $seen = [];
        for ($i = 0; $i < $width; $i++) {
            $name = $headerCells[$i] ?? '';
            $name = $name !== '' ? mb_substr($name, 0, 100) : 'Column ' . ($i + 1);
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $seen[$key]++;
                $name .= ' (' . $seen[$key] . ')';
            } else {
                $seen[$key] = 1;
            }
            $headers[] = $name;
        }

        foreach ($rows as &$row) {
            $row['cells'] = array_pad(array_slice($row['cells'], 0, $width), $width, '');
        }
        unset($row);

        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function cleanCell(mixed $value): string
    {
        $value = (string) $value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        // Strip control characters (keep normal spaces), collapse whitespace.
        $value = preg_replace('/[\x00-\x1F\x7F\x{200B}\x{FEFF}]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, self::MAX_CELL_LENGTH);
    }

    /** @return array<int, list<string>> */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw HttpException::badRequest('The file could not be read.');
        }

        $firstLine = (string) fgets($handle);
        rewind($handle);
        if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
            fread($handle, 3); // skip UTF-8 BOM
            $firstLine = substr($firstLine, 3);
        }

        $delimiter = ',';
        $best = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }

        $rows = [];
        $rowNumber = 0;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rowNumber++;
            if ($cells === [null]) {
                continue;
            }
            $rows[$rowNumber] = array_map('strval', array_slice($cells, 0, self::MAX_COLUMNS + 1));
            if ($rowNumber > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, list<string>> */
    private static function readXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to read XLSX files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw HttpException::badRequest('The file is not a valid XLSX workbook.');
        }

        try {
            $sheetPath = self::firstSheetPath($zip);
            $sharedStrings = self::sharedStrings($zip);
            $sheet = self::loadXml($zip, $sheetPath);
            if ($sheet === null) {
                throw HttpException::badRequest('The workbook has no readable worksheet.');
            }

            $rows = [];
            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $rowNode) {
                $rowNumber = (int) $rowNode->attributes()['r'];
                $cells = [];
                $nextIndex = 0;
                foreach ($rowNode->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                    $ref = (string) $cell->attributes()['r'];
                    $index = $ref !== '' ? self::columnIndex($ref) : $nextIndex;
                    $nextIndex = $index + 1;
                    if ($index >= self::MAX_COLUMNS + 1) {
                        continue;
                    }
                    $cells[$index] = self::cellValue($cell, $sharedStrings);
                }
                if ($cells === []) {
                    continue;
                }
                $max = max(array_keys($cells));
                $list = [];
                for ($i = 0; $i <= $max; $i++) {
                    $list[] = $cells[$i] ?? '';
                }
                $rows[$rowNumber > 0 ? $rowNumber : count($rows) + 1] = $list;
                if (count($rows) > self::MAX_ROWS + 50) {
                    break;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = self::loadXml($zip, 'xl/workbook.xml');
        $rels = self::loadXml($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook === null || $rels === null) {
            throw HttpException::badRequest('The file is not a valid XLSX workbook.');
        }

        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheet = ($workbook->xpath('//m:sheets/m:sheet') ?: [])[0] ?? null;
        $relationId = $sheet !== null
            ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']
            : '';

        foreach ($rels->children() as $relationship) {
            if ((string) $relationship['Id'] === $relationId) {
                $target = ltrim((string) $relationship['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = self::loadXml($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }

        $strings = [];
        foreach ($xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $item) {
            $strings[] = self::richText($item);
        }

        return $strings;
    }

    private static function richText(\SimpleXMLElement $node): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $children = $node->children($ns);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children($ns)->t;
        }

        return $text;
    }

    /** @param list<string> $sharedStrings */
    private static function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $type = (string) $cell->attributes()['t'];
        $children = $cell->children($ns);

        if ($type === 'inlineStr') {
            return isset($children->is) ? self::richText($children->is) : '';
        }

        $value = isset($children->v) ? (string) $children->v : '';

        return match ($type) {
            's' => $sharedStrings[(int) $value] ?? '',
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            'str', 'e' => $value,
            default => self::formatNumber($value),
        };
    }

    /** Whole numbers lose the ".0" Excel stores (e.g. Employee ID 1023). */
    private static function formatNumber(string $value): string
    {
        if ($value === '' || !is_numeric($value)) {
            return $value;
        }
        $number = (float) $value;
        if (floor($number) === $number && abs($number) < 1e15) {
            return number_format($number, 0, '.', '');
        }

        return $value;
    }

    private static function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private static function loadXml(\ZipArchive $zip, string $name): ?\SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw HttpException::badRequest('The workbook is too large to import.');
        }
        $contents = $zip->getFromName($name);
        if ($contents === false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }
}
