<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * M2 (FR-M2.3) — parse uploaded CSV / JSON / Excel files into columns + rows.
 * Returns ['columns' => string[], 'rows' => array<array<string,mixed>>].
 */
class FileParser
{
    public function parse(string $type, string $absolutePath, array $options = [], ?int $limit = null): array
    {
        $result = match ($type) {
            'csv'   => $this->csv($absolutePath, $options),
            'json'  => $this->json($absolutePath),
            'excel' => $this->excel($absolutePath, $options),
            default => ['columns' => [], 'rows' => []],
        };

        if ($limit !== null) {
            $result['rows'] = array_slice($result['rows'], 0, $limit);
        }

        return $result;
    }

    private function csv(string $path, array $options): array
    {
        $delimiter = $options['delimiter'] ?? ',';
        $hasHeader = $options['has_header'] ?? true;

        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new \RuntimeException('Unable to read file.');
        }

        $columns = [];
        $rows = [];
        $i = 0;
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($i === 0) {
                $columns = $hasHeader
                    ? array_map(fn ($c) => trim((string) $c), $line)
                    : array_map(fn ($n) => 'col_' . ($n + 1), array_keys($line));
                if ($hasHeader) {
                    $i++;
                    continue;
                }
            }
            $rows[] = $this->combine($columns, $line);
            $i++;
        }
        fclose($handle);

        return ['columns' => $columns, 'rows' => $rows];
    }

    private function json(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new \RuntimeException('Invalid JSON file.');
        }
        // Accept a list of objects, or an object wrapping a list under data/rows/items.
        if (! array_is_list($data)) {
            foreach (['data', 'rows', 'items', 'results'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $data = $data[$key];
                    break;
                }
            }
        }
        $data = array_is_list($data) ? $data : [$data];

        $columns = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $columns = array_values(array_unique([...$columns, ...array_keys($row)]));
            }
        }

        return ['columns' => $columns, 'rows' => array_map(fn ($r) => is_array($r) ? $r : ['value' => $r], $data)];
    }

    private function excel(string $path, array $options): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        $matrix = $sheet->toArray(null, true, false, false);

        if (empty($matrix)) {
            return ['columns' => [], 'rows' => []];
        }

        $hasHeader = $options['has_header'] ?? true;
        $header = array_shift($matrix);
        $columns = $hasHeader
            ? array_map(fn ($c) => trim((string) $c), $header)
            : array_map(fn ($n) => 'col_' . ($n + 1), array_keys($header));
        if (! $hasHeader) {
            array_unshift($matrix, $header); // first row was data, not header
        }

        $rows = array_map(fn ($line) => $this->combine($columns, $line), $matrix);

        return ['columns' => $columns, 'rows' => $rows];
    }

    // Pad/trim a data row to the column count and build an assoc array.
    private function combine(array $columns, array $line): array
    {
        $line = array_slice(array_pad($line, count($columns), null), 0, count($columns));

        return array_combine($columns, $line);
    }
}
