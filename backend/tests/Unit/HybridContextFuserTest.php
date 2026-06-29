<?php

namespace Tests\Unit;

use App\Services\Ai\HybridContextFuser;
use Tests\TestCase;

// M4 (FR-M4.7) — HybridContextFuser unit tests.
class HybridContextFuserTest extends TestCase
{
    private HybridContextFuser $fuser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuser = new HybridContextFuser();
    }

    public function test_fuse_with_rows_and_chunks(): void
    {
        $context = [
            'query_columns' => ['id', 'name', 'total'],
            'query_rows'    => [
                ['id' => 1, 'name' => 'Alice', 'total' => 100],
                ['id' => 2, 'name' => 'Bob',   'total' => 200],
            ],
            'rag_chunks' => [
                ['heading' => 'Section A', 'content' => 'Content about section A.', 'score' => 0.9],
            ],
        ];

        $result = $this->fuser->fuse($context);

        $this->assertArrayHasKey('narrative', $result);
        $this->assertArrayHasKey('table_md', $result);
        $this->assertArrayHasKey('fused_block', $result);

        $this->assertStringContainsString('Section A', $result['narrative']);
        $this->assertStringContainsString('id', $result['table_md']);
        $this->assertStringContainsString('Alice', $result['table_md']);
    }

    public function test_fuse_with_empty_rows(): void
    {
        $context = [
            'query_columns' => [],
            'query_rows'    => [],
            'rag_chunks'    => [],
        ];

        $result = $this->fuser->fuse($context);

        $this->assertSame('', $result['narrative']);
        $this->assertSame('', $result['table_md']);
        $this->assertSame('', $result['fused_block']);
    }

    public function test_markdown_table_has_header_and_separator(): void
    {
        $context = [
            'query_columns' => ['a', 'b'],
            'query_rows'    => [['a' => 'x', 'b' => 'y']],
            'rag_chunks'    => [],
        ];

        $result = $this->fuser->fuse($context);
        $this->assertStringContainsString('| a | b |', $result['table_md']);
        $this->assertStringContainsString('---', $result['table_md']);
    }

    public function test_pipe_in_cell_is_escaped(): void
    {
        $context = [
            'query_columns' => ['val'],
            'query_rows'    => [['val' => 'foo|bar']],
            'rag_chunks'    => [],
        ];

        $result = $this->fuser->fuse($context);
        $this->assertStringContainsString('foo\|bar', $result['table_md']);
    }
}
