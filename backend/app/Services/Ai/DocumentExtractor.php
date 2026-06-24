<?php

namespace App\Services\Ai;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * M3 (FR-M3.2) — extract plain text from uploaded reference documents so the
 * ingestion pipeline can chunk + embed it. Returns text with paragraph/line
 * breaks preserved as structure hints for structure-aware chunking (FR-M3.3).
 *
 * Supported: pdf | docx | xlsx | markdown (md/txt).
 */
class DocumentExtractor
{
    /** Map a filename/type to a normalised document type, or null if unsupported. */
    public static function detectType(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf'        => 'pdf',
            'docx'       => 'docx',
            'xlsx', 'xls' => 'xlsx',
            'md', 'markdown', 'txt' => 'markdown',
            default      => null,
        };
    }

    public function extract(string $type, string $absolutePath): string
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException("Unable to read document: {$absolutePath}");
        }

        return trim(match ($type) {
            'pdf'      => $this->pdf($absolutePath),
            'docx'     => $this->docx($absolutePath),
            'xlsx'     => $this->xlsx($absolutePath),
            'markdown' => $this->markdown($absolutePath),
            default    => throw new RuntimeException("Unsupported document type: {$type}"),
        });
    }

    private function pdf(string $path): string
    {
        return (new PdfParser())->parseFile($path)->getText();
    }

    // DOCX is a zip; the body text lives in word/document.xml. Convert paragraph
    // and break tags to newlines, then strip the remaining XML — no extra dep.
    private function docx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open DOCX archive.');
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();

        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
        $xml = preg_replace('/<w:br\s*\/>/', "\n", $xml);
        $xml = preg_replace('/<w:tab\s*\/>/', "\t", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // Flatten every sheet to "header: value" lines so tabular knowledge embeds
    // meaningfully (FR-M3.4 table-to-text).
    private function xlsx(string $path): string
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $out = [];
        foreach ($book->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, false);
            if (! $rows) {
                continue;
            }
            $out[] = '# ' . $sheet->getTitle();
            $header = array_map(fn ($c) => trim((string) $c), array_shift($rows) ?? []);
            foreach ($rows as $row) {
                $pairs = [];
                foreach ($row as $i => $cell) {
                    if ($cell === null || $cell === '') {
                        continue;
                    }
                    $label = $header[$i] ?? ('col_' . ($i + 1));
                    $pairs[] = "{$label}: {$cell}";
                }
                if ($pairs) {
                    $out[] = implode(', ', $pairs);
                }
            }
        }

        return implode("\n", $out);
    }

    private function markdown(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
