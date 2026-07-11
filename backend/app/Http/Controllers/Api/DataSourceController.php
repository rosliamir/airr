<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\DataSource;
use App\Services\AuditService;
use App\Services\ConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// M2 Layer 1 — data source connections. Read gated by datasources.view,
// writes by datasources.manage. Credentials are never returned to the client.
class DataSourceController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit, protected ConnectorService $connector) {}

    public function index(Request $request): JsonResponse
    {
        $query = DataSource::query()->with('project:id,code,name')->withCount('datasets');

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Visible if the viewer owns it or can see its project (admins: all).
        $viewer = $request->user();
        if (! $viewer->seesEverything()) {
            $query->where(function ($q) use ($viewer) {
                $q->where('created_by', $viewer->id)
                    ->orWhereHas('project', fn ($p) => $p->visibleTo($viewer));
            });
        }
        if ($pid = $request->input('project_id')) {
            $query->where('project_id', $pid);
        }

        return $this->sendOk($query->orderByDesc('created_at')->get()->map(fn (DataSource $d) => $this->row($d)));
    }

    public function show(DataSource $dataSource): JsonResponse
    {
        return $this->sendOk($this->row($dataSource->load('project:id,code,name'), true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSource($request);
        $source = DataSource::create([
            'project_id' => $data['project_id'] ?? null,
            'name'       => $data['name'],
            'type'       => $data['type'],
            'config'     => $data['config'] ?? [],
            'created_by' => $request->user()->id,
        ]);
        $this->audit->log('datasource.created', DataSource::class, $source->id, null, ['name' => $source->name, 'type' => $source->type]);
        $this->saveHistory($source, $request->user()->id, 'created');

        return $this->sendCreated($this->row($source->fresh('project')));
    }

    public function update(Request $request, DataSource $dataSource): JsonResponse
    {
        $data = $this->validateSource($request, $dataSource);
        // Keep existing credentials if config omitted on edit.
        $dataSource->fill([
            'project_id' => $data['project_id'] ?? $dataSource->project_id,
            'name'       => $data['name'],
            'type'       => $data['type'],
        ]);
        if (! empty($data['config'])) {
            $dataSource->config = $data['config'];
            $dataSource->status = 'unknown';
        }
        $dataSource->save();
        $this->audit->log('datasource.updated', DataSource::class, $dataSource->id);
        $this->saveHistory($dataSource, $request->user()->id, 'updated');

        return $this->sendOk($this->row($dataSource->fresh('project')));
    }

    public function destroy(DataSource $dataSource): JsonResponse
    {
        $dataSource->delete(); // soft delete; datasets cascade via DB FK (hard delete deferred)
        $this->audit->log('datasource.deleted', DataSource::class, $dataSource->id);

        return $this->sendNoContent();
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $dataSource = DataSource::withTrashed()->findOrFail($id);
        $dataSource->restore();
        $this->audit->log('datasource.restored', DataSource::class, $dataSource->id);

        return $this->sendOk($this->row($dataSource->fresh('project')));
    }

    // FR-M2.1/M2.2 — test connectivity and persist the result.
    public function test(DataSource $dataSource): JsonResponse
    {
        $result = $this->connector->test($dataSource);
        $dataSource->update(['status' => $result['ok'] ? 'ok' : 'error']);
        $this->audit->log('datasource.tested', DataSource::class, $dataSource->id, null, ['ok' => $result['ok']]);

        return $this->sendOk($result);
    }

    // FR-M2.4 — introspect schema and cache it.
    public function introspect(DataSource $dataSource): JsonResponse
    {
        try {
            $schema = $this->connector->introspect($dataSource);
            $dataSource->update(['schema_cache' => $schema, 'status' => 'ok']);
            $this->audit->log('datasource.introspected', DataSource::class, $dataSource->id, null, ['tables' => count($schema)]);

            return $this->sendOk(['tables' => $schema]);
        } catch (\Throwable $e) {
            $dataSource->update(['status' => 'error']);

            return $this->sendError(422, 'INTROSPECT_FAILED', $e->getMessage());
        }
    }

    // FR-M2.3 — upload/replace the file for a file-based source, then parse it.
    public function uploadFile(Request $request, DataSource $dataSource): JsonResponse
    {
        if (! $dataSource->isFile()) {
            return $this->sendError(422, 'NOT_A_FILE_SOURCE', 'This data source is not file-based.');
        }

        $hasFile    = $request->hasFile('file');
        $hasContent = $request->filled('json_content') && $dataSource->type === 'json';

        if (! $hasFile && ! $hasContent) {
            return $this->sendError(422, 'NO_INPUT', 'Provide a file or json_content for JSON sources.');
        }

        $request->validate([
            'file'         => 'nullable|file|mimes:csv,txt,json,xlsx,xls|max:10240',
            'json_content' => 'nullable|string',
            'has_header'   => 'nullable|boolean',
            'delimiter'    => 'nullable|string|max:2',
        ]);

        // Remove previously stored file.
        if ($old = ($dataSource->config['file_path'] ?? null)) {
            Storage::disk('local')->delete($old);
        }

        if ($hasContent) {
            // Validate it parses as JSON.
            $decoded = json_decode($request->input('json_content'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->sendError(422, 'INVALID_JSON', 'json_content is not valid JSON.');
            }
            $filename = 'data_files/json_' . $dataSource->id . '_' . time() . '.json';
            Storage::disk('local')->put($filename, $request->input('json_content'));
            $originalName = 'inline_' . now()->format('Ymd_His') . '.json';
            $path = $filename;
        } else {
            $path = $request->file('file')->store('data_files', 'local');
            $originalName = $request->file('file')->getClientOriginalName();
        }

        $options = array_filter([
            'has_header' => $request->boolean('has_header', true),
            'delimiter'  => $request->input('delimiter'),
        ], fn ($v) => $v !== null);

        $dataSource->config = array_merge($dataSource->config ?? [], [
            'file_path'     => $path,
            'original_name' => $originalName,
            'options'       => $options,
        ]);
        $dataSource->save();

        // Parse for schema + row count.
        try {
            $parsed = $this->connector->parseFile($dataSource);
            $dataSource->config = array_merge($dataSource->config, ['row_count' => count($parsed['rows'])]);
            $dataSource->schema_cache = $this->connector->introspect($dataSource);
            $dataSource->status = 'ok';
            $dataSource->save();
        } catch (\Throwable $e) {
            $dataSource->update(['status' => 'error']);

            return $this->sendError(422, 'PARSE_FAILED', $e->getMessage());
        }

        $this->audit->log('datasource.uploaded', DataSource::class, $dataSource->id, null, ['file' => $originalName]);
        $this->saveHistory($dataSource, $request->user()->id, 'uploaded');

        return $this->sendOk($this->row($dataSource->fresh('project')));
    }

    // Change history snapshots.
    public function history(DataSource $dataSource): JsonResponse
    {
        $rows = DB::table('data_source_histories as h')
            ->join('users as u', 'u.id', '=', 'h.changed_by')
            ->where('h.data_source_id', $dataSource->id)
            ->orderByDesc('h.created_at')
            ->limit(50)
            ->get(['h.id', 'h.action', 'h.snapshot', 'h.created_at', 'u.name as changed_by_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'              => $r->id,
            'action'          => $r->action,
            'snapshot'        => json_decode($r->snapshot, true),
            'changed_by_name' => $r->changed_by_name,
            'created_at'      => \Carbon\Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ]));
    }

    // Access logs from the central audit log.
    public function logs(DataSource $dataSource): JsonResponse
    {
        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.object_type', DataSource::class)
            ->where('a.object_id', (string) $dataSource->id)
            ->orderByDesc('a.created_at')
            ->limit(100)
            ->get(['a.id', 'a.action', 'a.new_values', 'a.created_at', 'u.name as user_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'         => $r->id,
            'event'      => $r->action,
            'properties' => json_decode($r->new_values ?? '{}', true),
            'user_name'  => $r->user_name ?? 'System',
            'created_at' => \Carbon\Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ]));
    }

    private function validateSource(Request $request, ?DataSource $source = null): array
    {
        // File sources receive their file via upload, so config is optional here.
        $type = $request->input('type');
        $configRequired = ($source || in_array($type, DataSource::FILE_TYPES, true)) ? 'nullable' : 'required';

        return $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'name'       => 'required|string|max:160',
            'type'       => ['required', Rule::in(DataSource::TYPES)],
            'config'     => "{$configRequired}|array",
        ]);
    }

    private function saveHistory(DataSource $source, int $userId, string $action): void
    {
        $cfg = $source->config ?? [];
        // Mask credentials before storing snapshot.
        $safeCfg = array_diff_key($cfg, array_flip(['password', 'token']));

        DB::table('data_source_histories')->insert([
            'data_source_id' => $source->id,
            'changed_by'     => $userId,
            'action'         => $action,
            'snapshot'       => json_encode([
                'name'          => $source->name,
                'type'          => $source->type,
                'project_id'    => $source->project_id,
                'status'        => $source->status,
                'config_safe'   => $safeCfg,
            ]),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // Credentials are masked — only non-secret descriptors leave the server.
    private function row(DataSource $d, bool $withSchema = false): array
    {
        $cfg = $d->config ?? [];
        if ($d->isFile()) {
            $safe = array_filter([
                'original_name' => $cfg['original_name'] ?? null,
                'row_count'     => $cfg['row_count'] ?? null,
            ], fn ($v) => $v !== null);
        } elseif ($d->isDatabase()) {
            $safe = array_filter([
                'host' => $cfg['host'] ?? null, 'port' => $cfg['port'] ?? null,
                'database' => $cfg['database'] ?? null, 'service_name' => $cfg['service_name'] ?? null,
                'username' => $cfg['username'] ?? null,
            ], fn ($v) => $v !== null);
        } else {
            $safe = ['base_url' => $cfg['base_url'] ?? null, 'auth_type' => $cfg['auth_type'] ?? 'none'];
        }

        return array_filter([
            'id'             => $d->id,
            'name'           => $d->name,
            'type'           => $d->type,
            'is_database'    => $d->isDatabase(),
            'is_file'        => $d->isFile(),
            'status'         => $d->status,
            'deleted_at'     => $d->deleted_at?->toISOString(),
            'project'        => $d->project ? ['id' => $d->project->id, 'code' => $d->project->code, 'name' => $d->project->name] : null,
            'config_summary' => $safe,
            'datasets_count' => $d->datasets_count ?? $d->datasets()->count(),
            'schema_tables'  => $withSchema ? ($d->schema_cache ?? []) : null,
            'created_at'     => $d->created_at,
        ], fn ($v) => $v !== null);
    }
}
