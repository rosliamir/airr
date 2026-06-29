<?php

namespace App\Services\Ai;

use App\Exceptions\SqlGuardrailException;
use App\Models\DataSource;

// M4 (FR-M4.9) — validate LLM-generated SQL before execution.
// Only SELECT statements are allowed; DML/DDL/dangerous constructs are rejected.
// Referenced tables must exist in the data source schema cache.
class SqlGuardrail
{
    /**
     * Validate and return the cleaned SQL string.
     *
     * @throws SqlGuardrailException on any violation
     */
    public function validate(string $sql, ?DataSource $dataSource = null): string
    {
        $clean = trim($sql);

        // Strip leading/trailing markdown fences if the LLM wrapped the SQL.
        $clean = preg_replace('/^```(?:sql)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $this->assertSelectOnly($clean);
        $this->assertNoStackedStatements($clean);
        $this->assertNoForbiddenKeywords($clean);

        if ($dataSource) {
            $this->assertKnownTables($clean, $dataSource);
        }

        return $clean;
    }

    private function assertSelectOnly(string $sql): void
    {
        $first = strtoupper(preg_replace('/\s+/', ' ', ltrim($sql)));

        if (! str_starts_with($first, 'SELECT') && ! str_starts_with($first, 'WITH')) {
            throw new SqlGuardrailException(
                'Only SELECT (or WITH…SELECT) statements are allowed. Got: ' . substr($first, 0, 40)
            );
        }
    }

    private function assertNoStackedStatements(string $sql): void
    {
        // Semicolon inside quotes is fine; bare semicolons between statements are not.
        $stripped = preg_replace("/'[^']*'/", "''", $sql); // remove string literals
        $stripped = preg_replace('/"[^"]*"/', '""', $stripped);

        if (substr_count($stripped, ';') > 1 ||
            (substr_count($stripped, ';') === 1 && ! str_ends_with(rtrim($stripped), ';'))) {
            throw new SqlGuardrailException('Stacked SQL statements are not allowed.');
        }
    }

    private function assertNoForbiddenKeywords(string $sql): void
    {
        $upper = strtoupper($sql);

        $forbidden = [
            'INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'CREATE ', 'ALTER ',
            'TRUNCATE ', 'REPLACE ', 'MERGE ', 'EXEC ', 'EXECUTE ',
            'GRANT ', 'REVOKE ', 'COPY ', 'CALL ',
        ];

        foreach ($forbidden as $kw) {
            // Keyword outside of string literals (rough heuristic — sufficient for guardrail)
            if (preg_match('/\b' . trim($kw) . '\b/', $upper)) {
                throw new SqlGuardrailException("Forbidden keyword [{$kw}] in generated SQL.");
            }
        }
    }

    private function assertKnownTables(string $sql, DataSource $dataSource): void
    {
        $schema = $dataSource->schema_cache;
        if (! is_array($schema) || empty($schema)) {
            return; // no schema cache — skip table validation
        }

        $knownTables = array_map('strtolower', array_keys($schema));

        // Extract bare table names after FROM / JOIN keywords (simplified regex).
        preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_.]*)/i', $sql, $matches);
        $referenced = array_map('strtolower', $matches[1] ?? []);

        foreach ($referenced as $tbl) {
            $bare = ltrim($tbl, '"'); // strip double-quote prefix if any
            $bare = explode('.', $bare);
            $bare = end($bare); // handle schema.table notation

            if (! in_array($bare, $knownTables, true)) {
                throw new SqlGuardrailException(
                    "Table [{$bare}] is not in the data source schema. Possible hallucination."
                );
            }
        }
    }
}
