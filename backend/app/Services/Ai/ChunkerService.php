<?php

namespace App\Services\Ai;

/**
 * M3 (FR-M3.3) — structure-aware chunking. Splits extracted text by logical
 * structure (Markdown headings, then blank-line paragraph groups) rather than a
 * fixed word count, so each chunk stays semantically coherent. Oversized
 * sections are further split on paragraph boundaries up to a soft char budget.
 *
 * Returns an ordered list of ['heading' => ?string, 'content' => string].
 */
class ChunkerService
{
    public function __construct(
        private readonly int $maxChars = 1200,
        private readonly int $minChars = 80,
    ) {}

    /**
     * @return array<int,array{heading:?string,content:string}>
     */
    public function chunk(string $text): array
    {
        $text = $this->normalise($text);
        if ($text === '') {
            return [];
        }

        $chunks = [];
        foreach ($this->sections($text) as [$heading, $body]) {
            foreach ($this->packParagraphs($body) as $content) {
                $chunks[] = ['heading' => $heading, 'content' => $content];
            }
        }

        // Merge a too-small trailing chunk into the previous one (avoids noise).
        return $this->mergeTiny($chunks);
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Split into [heading, body] sections at Markdown headings (#..######).
     * Text before the first heading becomes a section with a null heading.
     *
     * @return array<int,array{0:?string,1:string}>
     */
    private function sections(string $text): array
    {
        $lines = explode("\n", $text);
        $sections = [];
        $heading = null;
        $buffer = [];

        $flush = function () use (&$sections, &$heading, &$buffer) {
            $body = trim(implode("\n", $buffer));
            if ($body !== '' || $heading !== null) {
                $sections[] = [$heading, $body];
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/', trim($line), $m)) {
                $flush();
                $heading = trim($m[1]);
            } else {
                $buffer[] = $line;
            }
        }
        $flush();

        return $sections ?: [[null, $text]];
    }

    /**
     * Pack paragraphs into chunks up to maxChars, never splitting a paragraph
     * unless it alone exceeds the budget (then hard-split on sentence/space).
     *
     * @return array<int,string>
     */
    private function packParagraphs(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $paras = preg_split("/\n{2,}/", $body) ?: [$body];
        $out = [];
        $current = '';

        foreach ($paras as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (mb_strlen($para) > $this->maxChars) {
                if ($current !== '') {
                    $out[] = $current;
                    $current = '';
                }
                foreach ($this->hardSplit($para) as $piece) {
                    $out[] = $piece;
                }
                continue;
            }
            $candidate = $current === '' ? $para : "{$current}\n\n{$para}";
            if (mb_strlen($candidate) > $this->maxChars) {
                $out[] = $current;
                $current = $para;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    /** Split an oversized paragraph on whitespace near the char budget. */
    private function hardSplit(string $para): array
    {
        $words = preg_split('/\s+/', $para) ?: [];
        $out = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if (mb_strlen($candidate) > $this->maxChars) {
                $out[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    private function mergeTiny(array $chunks): array
    {
        $count = count($chunks);
        if ($count >= 2 && mb_strlen($chunks[$count - 1]['content']) < $this->minChars
            && $chunks[$count - 2]['heading'] === $chunks[$count - 1]['heading']) {
            $chunks[$count - 2]['content'] .= "\n\n" . $chunks[$count - 1]['content'];
            array_pop($chunks);
        }

        return $chunks;
    }
}
