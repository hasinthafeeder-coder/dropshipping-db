<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Minimal reader for workbooks produced by SimpleXlsxWriter (shared-string cells).
 */
trait ReadsSimpleXlsxWorkbook
{
    /**
     * @return list<list<string>>
     */
    protected function readSimpleXlsxSheetMatrix(string $absolutePath, int $sheetIndex = 1): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Workbook not found: '.$absolutePath);
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Unable to open workbook: '.$absolutePath);
        }

        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet'.$sheetIndex.'.xml');
        $zip->close();

        if (! is_string($sharedXml) || ! is_string($sheetXml)) {
            throw new RuntimeException('Workbook parts missing.');
        }

        $shared = [];
        if (preg_match_all('/<si><t(?:[^>]*)>(.*?)<\/t><\/si>/s', $sharedXml, $matches) > 0) {
            foreach ($matches[1] as $value) {
                $shared[] = html_entity_decode($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        }

        $matrix = [];
        if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rowMatches) > 0) {
            foreach ($rowMatches[1] as $rowXml) {
                $row = [];
                if (preg_match_all('/<c r="([A-Z]+)(\d+)" t="s"><v>(\d+)<\/v><\/c>/', $rowXml, $cellMatches, PREG_SET_ORDER) > 0) {
                    foreach ($cellMatches as $cell) {
                        $colIndex = $this->columnLettersToIndex($cell[1]);
                        $row[$colIndex] = $shared[(int) $cell[3]] ?? '';
                    }
                }
                if ($row !== []) {
                    ksort($row);
                    $matrix[] = array_values($row);
                }
            }
        }

        return $matrix;
    }

    private function columnLettersToIndex(string $letters): int
    {
        $index = 0;
        $chars = str_split($letters);
        foreach ($chars as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return $index - 1;
    }
}
