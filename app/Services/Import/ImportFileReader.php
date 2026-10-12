<?php

namespace App\Services\Import;

use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Streams rows from CSV or XLSX. CSV is read line-by-line (any size); XLSX uses PhpSpreadsheet
 * with data-only reading of a single worksheet. Values are returned as trimmed strings.
 */
class ImportFileReader
{
    /** @return array<string> worksheet names (empty for CSV) */
    public function sheets(string $path, string $type): array
    {
        if ($type !== 'xlsx') {
            return [];
        }

        return (new Xlsx)->listWorksheetNames($path);
    }

    /** @return Generator<int, array<int, string>> rowNumber (1-based, file row) => cells */
    public function rows(string $path, string $type, ?string $sheet = null): Generator
    {
        return $type === 'xlsx' ? $this->xlsxRows($path, $sheet) : $this->csvRows($path);
    }

    /** First non-empty row is the header row. */
    public function preview(string $path, string $type, ?string $sheet = null, int $sample = 8): array
    {
        $headers = null;
        $rows = [];
        $total = 0;
        foreach ($this->rows($path, $type, $sheet) as $n => $cells) {
            if ($headers === null) {
                $headers = $this->uniqueHeaders($cells);
                continue;
            }
            $total++;
            if (count($rows) < $sample) {
                $rows[] = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
            }
        }
        if ($headers === null) {
            throw new RuntimeException('The file is empty or has no header row.');
        }

        return ['headers' => $headers, 'sample' => $rows, 'total_rows' => $total];
    }

    public function uniqueHeaders(array $cells): array
    {
        $seen = [];
        $out = [];
        foreach ($cells as $i => $h) {
            $h = trim((string) $h) !== '' ? trim((string) $h) : 'Column '.($i + 1);
            $base = $h;
            for ($k = 2; isset($seen[mb_strtolower($h)]); $k++) {
                $h = $base.' ('.$k.')';
            }
            $seen[mb_strtolower($h)] = true;
            $out[] = $h;
        }

        return $out;
    }

    private function csvRows(string $path): Generator
    {
        $fh = fopen($path, 'rb');
        if (! $fh) {
            throw new RuntimeException('Unable to read the uploaded file.');
        }
        $first = fgets($fh) ?: '';
        rewind($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $delimiter = array_key_first($delims);
        $n = 0;
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $n++;
            if ($n === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            }
            $cells = array_map(function ($v) {
                $v = (string) $v;
                if (! mb_check_encoding($v, 'UTF-8')) {
                    $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                }

                return trim($v);
            }, $cells);
            if ($this->blank($cells)) {
                continue;
            }
            yield $n => $cells;
        }
        fclose($fh);
    }

    private function xlsxRows(string $path, ?string $sheet): Generator
    {
        $reader = new Xlsx;
        $reader->setReadEmptyCells(false);
        if ($sheet) {
            $reader->setLoadSheetsOnly([$sheet]);
        }
        $book = $reader->load($path);
        $ws = $sheet ? $book->getSheetByName($sheet) : $book->getSheet(0);
        if (! $ws) {
            throw new RuntimeException('Worksheet not found.');
        }
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestDataColumn());
        foreach ($ws->getRowIterator() as $row) {
            $cells = array_fill(0, $maxCol, '');
            $it = $row->getCellIterator();
            $it->setIterateOnlyExistingCells(true);
            foreach ($it as $cell) {
                $idx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                $cells[$idx] = $this->cellValue($cell);
            }
            if ($this->blank($cells)) {
                continue;
            }
            yield $row->getRowIndex() => $cells;
        }
        $book->disconnectWorksheets();
    }

    private function cellValue(Cell $cell): string
    {
        $v = $cell->getValue();
        if ($v === null) {
            return '';
        }
        if (is_string($v) && str_starts_with($v, '=')) {
            $v = $cell->getOldCalculatedValue() ?? ''; // never evaluate formulas from uploaded files
        }
        if ((is_int($v) || is_float($v)) && ExcelDate::isDateTime($cell)) {
            return ExcelDate::excelToDateTimeObject($v)->format(fmod((float) $v, 1.0) > 0 ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return sprintf('%.0f', $v); // phone numbers stored as numbers
        }
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }

        return trim((string) $v);
    }

    private function blank(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }
}
