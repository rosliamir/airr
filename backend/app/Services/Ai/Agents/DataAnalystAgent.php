<?php

namespace App\Services\Ai\Agents;

use App\Exceptions\SqlGuardrailException;
use App\Models\DataSource;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\Ai\SqlGuardrail;
use App\Services\ConnectorService;
use Illuminate\Support\Facades\Config;

// M4 (FR-M4.4 + FR-M4.9) — generate schema-aware SQL via LLM, validate it
// through the guardrail, then execute and return result rows.
class DataAnalystAgent implements AgentContract
{
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly AiProvider       $ai,
        private readonly ModelResolver    $resolver,
        private readonly SqlGuardrail     $guardrail,
        private readonly ConnectorService $connector,
    ) {}

    public function name(): string
    {
        return 'data_analyst';
    }

    /**
     * Reads:  context['intent'], context['data_source'], context['schema_snapshot']
     *         context['rag_chunks']
     * Writes: context['sql_generated']  — raw LLM output
     *         context['sql_validated']  — cleaned/validated SQL
     *         context['query_columns']  — column names
     *         context['query_rows']     — result rows (array of assoc arrays)
     *         context['query_count']
     *
     * @throws SqlGuardrailException
     */
    public function run(array $context): array
    {
        $intent      = $context['intent'] ?? [];
        $dataSource  = $context['data_source'] ?? null;
        $schema      = $context['schema_snapshot'] ?? [];
        $ragChunks   = $context['rag_chunks'] ?? [];
        $project     = $context['project'] ?? null;

        if (! $dataSource instanceof DataSource) {
            // No data source — skip SQL generation gracefully.
            $context['query_columns'] = [];
            $context['query_rows']    = [];
            $context['query_count']   = 0;
            return $context;
        }

        $model     = $this->resolver->model('reasoning', $project);
        $systemMsg = $this->buildSystemPrompt($schema, $ragChunks, $intent);
        $userMsg   = $intent['goal'] ?? ($context['prompt'] ?? '');

        $raw = $this->ai->generate($model, $userMsg, [
            'system'      => $systemMsg,
            'temperature' => 0.1,
        ]);

        $context['sql_generated'] = $raw;

        $sql = $this->extractSql($raw);
        $sql = $this->guardrail->validate($sql, $dataSource);

        $context['sql_validated'] = $sql;

        $result = $this->executeQuery($dataSource, $sql);

        $context['query_columns'] = $result['columns'];
        $context['query_rows']    = $result['rows'];
        $context['query_count']   = $result['count'];

        return $context;
    }

    private function buildSystemPrompt(array $schema, array $ragChunks, array $intent): string
    {
        $schemaText = $this->schemaToText($schema);
        $termsText  = $this->termMappingsToText($intent['term_mappings'] ?? []);
        $ragText    = $this->ragChunksToText($ragChunks);

        return <<<PROMPT
You are a SQL expert for a reporting system. Your job is to write a single valid SELECT query.

RULES:
- Output ONLY the SQL query — no explanation, no markdown, no prose.
- Only SELECT statements are allowed. No INSERT/UPDATE/DELETE/DROP/CREATE.
- Use only the tables and columns listed in the schema below.
- Limit results to {self::MAX_ROWS} rows using LIMIT if no limit is implied.

DATABASE SCHEMA:
{$schemaText}

BUSINESS TERM MAPPINGS:
{$termsText}

CONTEXT FROM KNOWLEDGE BASE:
{$ragText}
PROMPT;
    }

    private function schemaToText(array $schema): string
    {
        if (empty($schema)) {
            return '(schema not available)';
        }

        $lines = [];
        foreach ($schema as $table => $columns) {
            $cols = is_array($columns)
                ? implode(', ', array_keys($columns))
                : (string) $columns;
            $lines[] = "  {$table}({$cols})";
        }

        return implode("\n", $lines);
    }

    private function termMappingsToText(array $mappings): string
    {
        if (empty($mappings)) {
            return '(none)';
        }

        $lines = [];
        foreach ($mappings as $term => $info) {
            $lines[] = "  \"{$term}\" → {$info['table']}.{$info['column']}: {$info['definition']}";
        }

        return implode("\n", $lines);
    }

    private function ragChunksToText(array $chunks): string
    {
        if (empty($chunks)) {
            return '(none)';
        }

        return implode("\n\n", array_map(
            fn ($c) => ($c['heading'] ? "{$c['heading']}: " : '') . $c['content'],
            array_slice($chunks, 0, 4)
        ));
    }

    /** Extract the SQL block from an LLM response that may contain prose. */
    private function extractSql(string $raw): string
    {
        // Try to find a fenced ```sql ... ``` block first.
        if (preg_match('/```(?:sql)?\s*([\s\S]+?)```/i', $raw, $m)) {
            return trim($m[1]);
        }

        // Fall back: first line that starts with SELECT or WITH.
        foreach (explode("\n", $raw) as $line) {
            $upper = strtoupper(ltrim($line));
            if (str_starts_with($upper, 'SELECT') || str_starts_with($upper, 'WITH')) {
                return trim($raw); // return entire string; guardrail will clean it
            }
        }

        return trim($raw);
    }

    private function executeQuery(DataSource $dataSource, string $sql): array
    {
        // Re-use ConnectorService's DB connection logic via reflection of the
        // private method is fragile; instead we duplicate the minimal query execution.
        // The guardrail has already validated the SQL.
        $connName = 'airr_ds_' . $dataSource->id;
        $cfg      = $dataSource->config ?? [];
        $drivers  = [
            'postgres'  => ['driver' => 'pgsql',  'port' => 5432],
            'mysql'     => ['driver' => 'mysql',  'port' => 3306],
            'sqlserver' => ['driver' => 'sqlsrv', 'port' => 1433],
        ];
        $map = $drivers[$dataSource->type] ?? $drivers['postgres'];

        Config::set("database.connections.{$connName}", [
            'driver'   => $map['driver'],
            'host'     => $cfg['host']     ?? '127.0.0.1',
            'port'     => $cfg['port']     ?? $map['port'],
            'database' => $cfg['database'] ?? '',
            'username' => $cfg['username'] ?? '',
            'password' => $cfg['password'] ?? '',
            'charset'  => 'utf8',
        ]);

        try {
            $rows = \DB::connection($connName)->select($sql);
            $rows = array_slice(array_map(fn ($r) => (array) $r, $rows), 0, self::MAX_ROWS);

            return [
                'columns' => $rows ? array_keys($rows[0]) : [],
                'rows'    => $rows,
                'count'   => count($rows),
            ];
        } finally {
            \DB::purge($connName);
        }
    }
}
