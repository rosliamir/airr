<?php

namespace Tests\Unit;

use App\Services\Ai\ChunkerService;
use PHPUnit\Framework\TestCase;

// M3 (FR-M3.3) — structure-aware chunking.
class ChunkerServiceTest extends TestCase
{
    public function test_splits_by_markdown_headings(): void
    {
        $text = "# Intro\nAIRR overview.\n\n## Security\nZero-trust filtering.";
        $chunks = (new ChunkerService())->chunk($text);

        $this->assertCount(2, $chunks);
        $this->assertSame('Intro', $chunks[0]['heading']);
        $this->assertStringContainsString('AIRR overview', $chunks[0]['content']);
        $this->assertSame('Security', $chunks[1]['heading']);
    }

    public function test_text_without_headings_returns_single_chunk(): void
    {
        $chunks = (new ChunkerService())->chunk("Just a plain paragraph of text.");

        $this->assertCount(1, $chunks);
        $this->assertNull($chunks[0]['heading']);
    }

    public function test_oversized_section_is_split_within_budget(): void
    {
        $chunker = new ChunkerService(maxChars: 100);
        $para = str_repeat('word ', 60); // ~300 chars, no headings
        $chunks = $chunker->chunk($para);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(100, mb_strlen($c['content']));
        }
    }

    public function test_packs_multiple_small_paragraphs_together(): void
    {
        $chunker = new ChunkerService(maxChars: 1000);
        $text = "Para one.\n\nPara two.\n\nPara three.";
        $chunks = $chunker->chunk($text);

        // All small paras fit under budget → one packed chunk.
        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Para one', $chunks[0]['content']);
        $this->assertStringContainsString('Para three', $chunks[0]['content']);
    }

    public function test_empty_text_returns_no_chunks(): void
    {
        $this->assertSame([], (new ChunkerService())->chunk("   \n\n  "));
    }
}
