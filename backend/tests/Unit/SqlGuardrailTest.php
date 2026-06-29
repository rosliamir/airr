<?php

namespace Tests\Unit;

use App\Exceptions\SqlGuardrailException;
use App\Services\Ai\SqlGuardrail;
use Tests\TestCase;

// M4 (FR-M4.9) — SqlGuardrail unit tests.
class SqlGuardrailTest extends TestCase
{
    private SqlGuardrail $guardrail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardrail = new SqlGuardrail();
    }

    public function test_valid_select_passes(): void
    {
        $sql = 'SELECT id, name FROM users WHERE active = 1';
        $result = $this->guardrail->validate($sql);
        $this->assertStringContainsString('SELECT', strtoupper($result));
    }

    public function test_with_cte_select_passes(): void
    {
        $sql = 'WITH cte AS (SELECT id FROM users) SELECT * FROM cte';
        $result = $this->guardrail->validate($sql);
        $this->assertStringContainsStringIgnoringCase('WITH', $result);
    }

    public function test_update_statement_rejected(): void
    {
        $this->expectException(SqlGuardrailException::class);
        $this->guardrail->validate('UPDATE users SET name = "x"');
    }

    public function test_delete_statement_rejected(): void
    {
        $this->expectException(SqlGuardrailException::class);
        $this->guardrail->validate('DELETE FROM users WHERE id = 1');
    }

    public function test_drop_statement_rejected(): void
    {
        $this->expectException(SqlGuardrailException::class);
        $this->guardrail->validate('DROP TABLE users');
    }

    public function test_insert_statement_rejected(): void
    {
        $this->expectException(SqlGuardrailException::class);
        $this->guardrail->validate('INSERT INTO users (name) VALUES ("hack")');
    }

    public function test_stacked_statements_rejected(): void
    {
        $this->expectException(SqlGuardrailException::class);
        $this->guardrail->validate('SELECT 1; DROP TABLE users');
    }

    public function test_markdown_fences_stripped(): void
    {
        $sql = "```sql\nSELECT id FROM users\n```";
        $result = $this->guardrail->validate($sql);
        $this->assertStringNotContainsString('```', $result);
        $this->assertStringContainsString('SELECT', strtoupper($result));
    }

    public function test_unknown_table_rejected_when_schema_provided(): void
    {
        $dataSource = new \App\Models\DataSource();
        $dataSource->forceFill(['schema_cache' => ['users' => ['id', 'name']]]);

        $this->expectException(SqlGuardrailException::class);
        $this->guardrail->validate('SELECT * FROM ghost_table', $dataSource);
    }

    public function test_known_table_passes_with_schema(): void
    {
        $dataSource = new \App\Models\DataSource();
        $dataSource->forceFill(['schema_cache' => ['users' => ['id', 'name']]]);

        $result = $this->guardrail->validate('SELECT id, name FROM users', $dataSource);
        $this->assertStringContainsString('SELECT', strtoupper($result));
    }
}
