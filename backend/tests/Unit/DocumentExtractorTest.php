<?php

namespace Tests\Unit;

use App\Services\Ai\DocumentExtractor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

// M3 (FR-M3.2) — document text extraction.
class DocumentExtractorTest extends TestCase
{
    public function test_detects_supported_types(): void
    {
        $this->assertSame('pdf', DocumentExtractor::detectType('report.pdf'));
        $this->assertSame('docx', DocumentExtractor::detectType('Spec.DOCX'));
        $this->assertSame('xlsx', DocumentExtractor::detectType('data.xlsx'));
        $this->assertSame('markdown', DocumentExtractor::detectType('notes.md'));
        $this->assertSame('markdown', DocumentExtractor::detectType('readme.txt'));
        $this->assertNull(DocumentExtractor::detectType('archive.zip'));
    }

    public function test_extracts_markdown_verbatim(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kb') . '.md';
        file_put_contents($path, "# Title\nHello AIRR.");

        $text = (new DocumentExtractor())->extract('markdown', $path);

        $this->assertStringContainsString('# Title', $text);
        $this->assertStringContainsString('Hello AIRR.', $text);
        unlink($path);
    }

    public function test_extracts_docx_paragraph_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kb') . '.docx';
        $xml = '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
            . '<w:p><w:r><w:t>Zero-trust filtering</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>inside PostgreSQL</w:t></w:r></w:p>'
            . '</w:body></w:document>';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $text = (new DocumentExtractor())->extract('docx', $path);

        $this->assertStringContainsString('Zero-trust filtering', $text);
        $this->assertStringContainsString('inside PostgreSQL', $text);
        // Paragraphs become separate lines.
        $this->assertStringContainsString("\n", $text);
        unlink($path);
    }

    public function test_unsupported_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $path = tempnam(sys_get_temp_dir(), 'kb');
        try {
            (new DocumentExtractor())->extract('bogus', $path);
        } finally {
            unlink($path);
        }
    }
}
