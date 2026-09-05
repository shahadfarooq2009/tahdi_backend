<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * Minimal XLSX reader (no external dependencies).
 */
final class SimpleXlsxReader
{
    /** @var string[] */
    private array $sharedStrings = [];

    /** @var array<string, string> */
    private array $sheetPaths = [];

    public function __construct(private readonly string $filePath)
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Excel file not found.');
        }
    }

    /**
     * @return array<int, array{name: string, rows: array<int, array<int, string>>}>
     */
    public function sheets(): array
    {
        $zip = new ZipArchive();

        if ($zip->open($this->filePath) !== true) {
            throw new RuntimeException('Unable to open Excel file.');
        }

        $this->sharedStrings = $this->readSharedStrings($zip);
        $this->sheetPaths = $this->readSheetPaths($zip);

        $sheets = [];

        foreach ($this->sheetPaths as $name => $path) {
            $xml = $zip->getFromName($path);

            if ($xml === false) {
                continue;
            }

            $sheets[] = [
                'name' => $name,
                'rows' => $this->parseSheetRows($xml),
            ];
        }

        $zip->close();

        return $sheets;
    }

    /**
     * @return string[]
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];

        foreach ($xpath->query('//m:si') as $si) {
            $parts = [];

            foreach ($xpath->query('.//m:t', $si) as $textNode) {
                $parts[] = $textNode->textContent;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function readSheetPaths(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Invalid Excel workbook structure.');
        }

        $rels = [];
        $relsDoc = new \DOMDocument();
        $relsDoc->loadXML($relsXml);
        $relsXPath = new \DOMXPath($relsDoc);
        $relsXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        foreach ($relsXPath->query('//r:Relationship') as $rel) {
            $id = $rel->attributes?->getNamedItem('Id')?->nodeValue;
            $target = $rel->attributes?->getNamedItem('Target')?->nodeValue;

            if ($id && $target) {
                $rels[$id] = str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'xl/'.ltrim($target, '/');
            }
        }

        $workbookDoc = new \DOMDocument();
        $workbookDoc->loadXML($workbookXml);
        $workbookXPath = new \DOMXPath($workbookDoc);
        $workbookXPath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbookXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = [];

        foreach ($workbookXPath->query('//m:sheet') as $sheet) {
            $name = $sheet->attributes?->getNamedItem('name')?->nodeValue ?? '';
            $relId = $sheet->attributes?->getNamedItemNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id'
            )?->nodeValue;

            if ($name === '' || ! $relId || ! isset($rels[$relId])) {
                continue;
            }

            $sheets[$name] = $rels[$relId];
        }

        return $sheets;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseSheetRows(string $xml): array
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];

        foreach ($xpath->query('//m:sheetData/m:row') as $rowNode) {
            $rowIndex = (int) ($rowNode->attributes?->getNamedItem('r')?->nodeValue ?? count($rows) + 1);
            $cells = [];

            foreach ($xpath->query('m:c', $rowNode) as $cell) {
                $ref = $cell->attributes?->getNamedItem('r')?->nodeValue ?? '';
                $colIndex = $this->columnIndexFromCellRef($ref);
                $type = $cell->attributes?->getNamedItem('t')?->nodeValue;
                $valueNode = $xpath->query('m:v', $cell)->item(0);
                $inlineNode = $xpath->query('m:is/m:t', $cell)->item(0);
                $value = '';

                if ($inlineNode) {
                    $value = trim($inlineNode->textContent);
                } elseif ($valueNode) {
                    $raw = $valueNode->textContent;

                    if ($type === 's') {
                        $value = $this->sharedStrings[(int) $raw] ?? '';
                    } else {
                        $value = $raw;
                    }
                }

                $cells[$colIndex] = trim($value);
            }

            if ($cells !== []) {
                ksort($cells);
                $maxCol = max(array_keys($cells));
                $normalized = [];

                for ($col = 0; $col <= $maxCol; $col++) {
                    $normalized[] = $cells[$col] ?? '';
                }

                $rows[$rowIndex] = $normalized;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    private function columnIndexFromCellRef(string $ref): int
    {
        if (! preg_match('/^([A-Z]+)/', strtoupper($ref), $matches)) {
            return 0;
        }

        $letters = $matches[1];
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }
}
