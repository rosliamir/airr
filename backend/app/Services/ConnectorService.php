<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\DataSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M2 connector engine. Tests connections, introspects schemas, and runs
 * datasets (bound parameters) for DB and REST sources. All execution is
 * parameterised — never string-interpolated — to prevent SQL injection.
 */
class ConnectorService
{
    private const PREVIEW_LIMIT = 100;

    public function __construct(protected FileParser $files) {}

    // FR-M2.1 / M2.2 / M2.3 — verify a source is reachable / present.
    public function test(DataSource $source): array
    {
        try {
            if ($source->isFile()) {
                $path = $source->config['file_path'] ?? null;
                if (! $path || ! Storage::disk('local')->exists($path)) {
                    return ['ok' => false, 'message' => 'No file uploaded yet.'];
                }

                return ['ok' => true, 'message' => 'File present and readable.'];
            }

            if ($source->isDatabase()) {
                // Oracle has no bare SELECT — it requires FROM DUAL.
                $ping = $source->type === 'oracle' ? 'select 1 from dual' : 'select 1';
                $this->dbConnection($source)->select($ping);
            } else {
                $cfg = $source->config ?? [];
                $res = $this->http($source)->get(rtrim($cfg['base_url'] ?? '', '/'));
                if ($res->status() >= 500) {
                    return ['ok' => false, 'message' => "Endpoint returned HTTP {$res->status()}"];
                }
            }

            return ['ok' => true, 'message' => 'Connection successful.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $this->purge($source);
        }
    }

    // Parse an uploaded file: cache columns as schema + remember row count.
    public function parseFile(DataSource $source, ?int $limit = null): array
    {
        $cfg = $source->config ?? [];
        $path = $cfg['file_path'] ?? null;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('No file uploaded.');
        }

        return $this->files->parse($source->type, Storage::disk('local')->path($path), $cfg['options'] ?? [], $limit);
    }

    // FR-M2.4 — read tables + columns into a cacheable structure (DB or file).
    public function introspect(DataSource $source): array
    {
        if ($source->isFile()) {
            $parsed = $this->parseFile($source);
            $name = $source->config['original_name'] ?? 'file';
            $cols = array_map(fn ($c) => ['name' => $c, 'type' => 'string'], $parsed['columns']);

            return [['table' => $name, 'columns' => $cols]];
        }

        if (! $source->isDatabase()) {
            return [];
        }

        try {
            $conn = $this->dbConnection($source);
            $tables = [];

            // Oracle exposes its catalogue via ALL_TAB_COLUMNS; the others share
            // the SQL-standard information_schema.columns view.
            if ($source->type === 'oracle') {
                $rows = $conn->select(
                    'select table_name, column_name, data_type
                     from user_tab_columns order by table_name, column_id'
                );
                foreach ($rows as $r) {
                    $t = $r->table_name ?? $r->TABLE_NAME;
                    $tables[$t][] = ['name' => $r->column_name ?? $r->COLUMN_NAME, 'type' => $r->data_type ?? $r->DATA_TYPE];
                }
            } else {
                // Scope to the relevant schema/db; MySQL returns UPPERCASE column
                // names from information_schema, so normalise keys case-insensitively.
                $cfg = $source->config ?? [];
                $filter = match ($source->type) {
                    'postgres' => "where table_schema = 'public'",
                    'mysql'    => 'where table_schema = ' . $conn->getPdo()->quote($cfg['database'] ?? ''),
                    default    => '',
                };
                $rows = $conn->select(
                    "select table_name, column_name, data_type
                     from information_schema.columns {$filter}
                     order by table_name, ordinal_position"
                );
                foreach ($rows as $r) {
                    $a = array_change_key_case((array) $r);
                    $tables[$a['table_name']][] = ['name' => $a['column_name'], 'type' => $a['data_type']];
                }
            }

            return collect($tables)->map(fn ($cols, $name) => ['table' => $name, 'columns' => $cols])->values()->all();
        } finally {
            $this->purge($source);
        }
    }

    // FR-M2.5 — run a dataset with runtime parameter values; returns paginated rows
    // (default 20/page) plus total count for page navigation. Pass $all=true to
    // fetch every matching row unpaginated — used for actual report execution
    // (Run/Preview/export), where the output must reflect the real, complete
    // data rather than the small page shown in the Dataset module's own
    // testing/preview screen.
    public function run(Dataset $dataset, array $paramValues, int $page = 1, int $perPage = 20, bool $all = false): array
    {
        $source = $dataset->dataSource;
        $params = $this->resolveParams($dataset, $paramValues);
        $page = max(1, $page);
        $perPage = max(1, min(self::PREVIEW_LIMIT, $perPage));

        if ($source->isFile()) {
            return $this->runFile($source, $params, $page, $perPage, $all);
        }

        return $source->isDatabase()
            ? $this->runSql($source, $dataset, $params, $page, $perPage, $all)
            : $this->runApi($source, $dataset, $params, $page, $perPage);
    }

    // File datasets read the parsed rows; params matching a column filter by
    // equality (simple, case-insensitive). Empty params return all rows.
    private function runFile(DataSource $source, array $params, int $page, int $perPage, bool $all = false): array
    {
        $parsed = $this->parseFile($source);
        $rows = $parsed['rows'];

        $filters = array_filter($params, fn ($v) => $v !== null && $v !== '');
        if ($filters) {
            $rows = array_values(array_filter($rows, function ($row) use ($filters) {
                foreach ($filters as $col => $val) {
                    if (! array_key_exists($col, $row)) {
                        continue;
                    }
                    if (Str::lower((string) $row[$col]) !== Str::lower((string) $val)) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $total = count($rows);
        if (! $all) {
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }

        return $this->paginated($parsed['columns'], $rows, $total, $page, $all ? $total : $perPage);
    }

    // --- DB ---

    private function runSql(DataSource $source, Dataset $dataset, array $params, int $page, int $perPage, bool $all = false): array
    {
        $sql = trim((string) $dataset->query);
        $this->guardSelect($sql);

        try {
            $conn = $this->dbConnection($source);
            $offset = ($page - 1) * $perPage;
            $execSql = $all ? $sql : $this->applyPagination($sql, $source->type, $offset, $perPage);

            try {
                $rows = $conn->select($execSql, $params);
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'ORA-00918') || str_contains($e->getMessage(), 'ambiguously defined')) {
                    throw new \RuntimeException(
                        'This query selects two columns with the same name from different tables '
                        . '(e.g. a join key present in both, like "select a.*, b.*"). Pagination needs to '
                        . 'wrap the query, which Oracle rejects when column names collide — please alias '
                        . 'the duplicate column(s) in the SQL (e.g. "b.NO_BIL_PELBAGAI AS DTL_NO_BIL_PELBAGAI").'
                    );
                }
                throw $e;
            }

            $total = $all ? count($rows) : null;
            if (! $all) {
                try {
                    $countRow = $conn->selectOne("SELECT COUNT(*) AS total FROM ({$sql}) airr_count", $params);
                    $total = (int) ((array) $countRow)['total'];
                } catch (\Throwable) {
                    // Best-effort — some hand-written queries won't wrap cleanly for COUNT; page nav
                    // just won't know the exact last page in that case, rows themselves still work.
                }
            }

            // Strip the internal ROWNUM bookkeeping column added for Oracle pagination.
            $rows = array_map(function ($r) {
                $row = (array) $r;
                unset($row['airr_rnum'], $row['AIRR_RNUM']);

                return $row;
            }, $rows);

            return $this->paginated(
                $rows ? array_keys($rows[0]) : [],
                $rows,
                $total,
                $page,
                $all ? $total : $perPage,
            );
        } finally {
            $this->purge($source);
        }
    }

    // Push LIMIT/OFFSET (or ANSI OFFSET…FETCH for SQL Server) down into the query
    // itself — never fetch the full result set into PHP just to slice it, that's
    // what exhausts memory on large joins.
    private function applyPagination(string $sql, string $sourceType, int $offset, int $perPage): string
    {
        if ($sourceType === 'oracle') {
            // Classic ROWNUM double-wrap — portable across Oracle versions (incl.
            // pre-12c, which has no ANSI OFFSET…FETCH support).
            $maxRow = $offset + $perPage;

            return "SELECT * FROM (SELECT airr_page.*, ROWNUM airr_rnum FROM ({$sql}) airr_page WHERE ROWNUM <= {$maxRow}) WHERE airr_rnum > {$offset}";
        }

        if ($sourceType === 'sqlserver') {
            return "SELECT * FROM ({$sql}) airr_page OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";
        }

        return "SELECT * FROM ({$sql}) airr_page LIMIT {$perPage} OFFSET {$offset}";
    }

    private function paginated(array $columns, array $rows, ?int $total, int $page, int $perPage): array
    {
        return [
            'columns'      => $columns,
            'rows'         => $rows,
            'count'        => count($rows),
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => $total !== null ? (int) ceil($total / $perPage) : null,
        ];
    }

    // Only single SELECT statements may run (guardrail).
    private function guardSelect(string $sql): void
    {
        $clean = trim($sql, "; \t\n");
        if (! Str::startsWith(Str::lower($clean), 'select') && ! Str::startsWith(Str::lower($clean), 'with')) {
            throw new \RuntimeException('Only SELECT queries are allowed.');
        }
        if (str_contains($clean, ';')) {
            throw new \RuntimeException('Multiple statements are not allowed.');
        }
    }

    // Driver + default port per supported database type.
    private const DRIVERS = [
        'postgres'  => ['driver' => 'pgsql', 'port' => 5432],
        'mysql'     => ['driver' => 'mysql', 'port' => 3306],
        'sqlserver' => ['driver' => 'sqlsrv', 'port' => 1433],
        'oracle'    => ['driver' => 'oracle', 'port' => 1521], // needs yajra/laravel-oci8 + oci8 ext
    ];

    private function dbConnection(DataSource $source)
    {
        $cfg = $source->config ?? [];
        $name = 'airr_ds_' . $source->id;
        $map = self::DRIVERS[$source->type] ?? self::DRIVERS['postgres'];

        $conn = [
            'driver'   => $map['driver'],
            'host'     => $cfg['host'] ?? '127.0.0.1',
            'port'     => $cfg['port'] ?? $map['port'],
            'database' => $cfg['database'] ?? '',
            'username' => $cfg['username'] ?? '',
            'password' => $cfg['password'] ?? '',
            'charset'  => 'utf8',
        ];

        if ($source->type === 'postgres') {
            $conn['sslmode'] = $cfg['sslmode'] ?? 'prefer';
            $conn['search_path'] = 'public';
        } elseif ($source->type === 'oracle') {
            // Oracle identifies the DB by SID or service name, not a db name.
            $conn['service_name'] = $cfg['service_name'] ?? null;
            $conn['sid'] = $cfg['sid'] ?? null;
            $conn['charset'] = 'AL32UTF8';
        }

        config(["database.connections.{$name}" => $conn]);

        return DB::connection($name);
    }

    private function purge(DataSource $source): void
    {
        $name = 'airr_ds_' . $source->id;
        DB::purge($name);
    }

    // --- API ---

    private function runApi(DataSource $source, Dataset $dataset, array $params, int $page, int $perPage): array
    {
        $cfg = $source->config ?? [];
        $path = $this->interpolate((string) $dataset->query, $params);
        $url = rtrim($cfg['base_url'] ?? '', '/') . '/' . ltrim($path, '/');
        $method = Str::lower($dataset->method ?: 'get');

        $request = $this->http($source);
        $res = $method === 'post'
            ? $request->withBody($this->interpolate((string) $dataset->body, $params), 'application/json')->post($url)
            : $request->get($url);

        $json = $res->json();
        // Accept a list directly, or unwrap a common envelope ({data|rows|items|results: [...]}).
        if (is_array($json) && ! array_is_list($json)) {
            foreach (['data', 'rows', 'items', 'results'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    $json = $json[$key];
                    break;
                }
            }
        }
        // GraphQL-style: after unwrapping `data` we may still hold a single-key
        // object wrapping the list (e.g. {countries: [...]}). Descend into it.
        if (is_array($json) && ! array_is_list($json) && count($json) === 1) {
            $only = reset($json);
            if (is_array($only) && array_is_list($only)) {
                $json = $only;
            }
        }
        $rows = is_array($json) && array_is_list($json) ? $json : [$json];
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->paginated(
            $rows && is_array($rows[0]) ? array_keys($rows[0]) : [],
            $rows,
            $total,
            $page,
            $perPage,
        ) + ['status' => $res->status()];
    }

    // Simple GET against an API-type DataSource's base URL — used to resolve
    // {{API:name|field}} / {{SYSTEM:API|name:field}} constant tokens, which
    // reference an ad-hoc output field rather than running a bound Dataset.
    public function callApiSource(DataSource $source): array
    {
        $cfg = $source->config ?? [];
        $res = $this->http($source)->get(rtrim($cfg['base_url'] ?? '', '/'));
        $json = $res->json();

        return is_array($json) ? $json : [];
    }

    private function http(DataSource $source)
    {
        $cfg = $source->config ?? [];
        $req = Http::timeout(15)->acceptJson();

        if (($cfg['auth_type'] ?? 'none') === 'bearer' && ! empty($cfg['token'])) {
            $req = $req->withToken($cfg['token']);
        } elseif (($cfg['auth_type'] ?? 'none') === 'header' && ! empty($cfg['header_name'])) {
            $req = $req->withHeaders([$cfg['header_name'] => $cfg['token'] ?? '']);
        }

        return $req;
    }

    // {{name}} placeholder substitution (URL-encoded) for API path/body.
    private function interpolate(string $template, array $params): string
    {
        foreach ($params as $key => $value) {
            $template = str_replace('{{' . $key . '}}', rawurlencode((string) $value), $template);
        }

        return $template;
    }

    // --- params ---

    // Merge provided values with declared defaults; cast by declared type.
    private function resolveParams(Dataset $dataset, array $values): array
    {
        $out = [];
        foreach ($dataset->parameters ?? [] as $p) {
            $name = $p['name'];
            $val = $values[$name] ?? ($p['default'] ?? null);
            $out[$name] = match ($p['type'] ?? 'text') {
                'number'  => is_numeric($val) ? $val + 0 : $val,
                'boolean' => filter_var($val, FILTER_VALIDATE_BOOLEAN),
                default   => $val,
            };
        }

        return $out;
    }
}
