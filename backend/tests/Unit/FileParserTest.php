<?php

namespace Tests\Unit;

use App\Services\FileParser;
use PHPUnit\Framework\TestCase;

// M2 (FR-M2.3) — file parsing into columns + rows.
class FileParserTest extends TestCase
{
    private function tmp(string $content, string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fp') . '.' . $ext;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_parses_csv_with_header(): void
    {
        $path = $this->tmp("name,age\nAli,30\nAbu,25\n", 'csv');
        $out = (new FileParser())->parse('csv', $path);

        $this->assertSame(['name', 'age'], $out['columns']);
        $this->assertCount(2, $out['rows']);
        $this->assertSame('Ali', $out['rows'][0]['name']);
        $this->assertSame('25', $out['rows'][1]['age']);
        unlink($path);
    }

    public function test_csv_without_header_generates_column_names(): void
    {
        $path = $this->tmp("Ali,30\nAbu,25\n", 'csv');
        $out = (new FileParser())->parse('csv', $path, ['has_header' => false]);

        $this->assertSame(['col_1', 'col_2'], $out['columns']);
        $this->assertCount(2, $out['rows']);
        unlink($path);
    }

    public function test_csv_honours_custom_delimiter(): void
    {
        $path = $this->tmp("name;city\nAli;KL\n", 'csv');
        $out = (new FileParser())->parse('csv', $path, ['delimiter' => ';']);

        $this->assertSame(['name', 'city'], $out['columns']);
        $this->assertSame('KL', $out['rows'][0]['city']);
        unlink($path);
    }

    public function test_parses_json_list_and_envelope(): void
    {
        $list = $this->tmp('[{"a":1,"b":2},{"a":3,"b":4}]', 'json');
        $env = $this->tmp('{"data":[{"a":1},{"a":9}]}', 'json');
        $parser = new FileParser();

        $this->assertCount(2, $parser->parse('json', $list)['rows']);
        $this->assertSame(9, $parser->parse('json', $env)['rows'][1]['a']);
        unlink($list);
        unlink($env);
    }

    public function test_limit_truncates_rows(): void
    {
        $path = $this->tmp("n\n1\n2\n3\n4\n", 'csv');
        $out = (new FileParser())->parse('csv', $path, [], 2);

        $this->assertCount(2, $out['rows']);
        unlink($path);
    }

    public function test_invalid_json_throws(): void
    {
        $path = $this->tmp('not-json', 'json');
        $this->expectException(\RuntimeException::class);
        try {
            (new FileParser())->parse('json', $path);
        } finally {
            unlink($path);
        }
    }
}
