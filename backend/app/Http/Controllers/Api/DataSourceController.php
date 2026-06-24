<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\DataSource;
use App\Services\AuditService;
use App\Services\ConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return $this->sendOk($this->row($dataSource->fresh('project')));
    }

    public function destroy(DataSource $dataSource): JsonResponse
    {
        $dataSource->delete(); // datasets cascade
        $this->audit->log('datasource.deleted', DataSource::class, $dataSource->id);

        return $this->sendNoContent();
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
        $request->validate([
            'file'       => 'required|file|mimes:csv,txt,json,xlsx,xls|max:10240',
            'has_header' => 'nullable|boolean',
            'delimiter'  => 'nullable|string|max:2',
        ]);

        // Remove a previously stored file.
        if ($old = ($dataSource->config['file_path'] ?? null)) {
            Storage::disk('local')->delete($old);
        }

        $path = $request->file('file')->store('data_files', 'local');
        $options = array_filter([
            'has_header' => $request->boolean('has_header', true),
            'delimiter'  => $request->input('delimiter'),
        ], fn ($v) => $v !== null);

        $dataSource->config = array_merge($dataSource->config ?? [], [
            'file_path'     => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
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

        $this->audit->log('datasource.uploaded', DataSource::class, $dataSource->id, null, ['file' => $dataSource->config['original_name']]);

        return $this->sendOk($this->row($dataSource->fresh('project')));
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
            'project'        => $d->project ? ['id' => $d->project->id, 'code' => $d->project->code, 'name' => $d->project->name] : null,
            'config_summary' => $safe,
            'datasets_count' => $d->datasets_count ?? $d->datasets()->count(),
            'schema_tables'  => $withSchema ? ($d->schema_cache ?? []) : null,
            'created_at'     => $d->created_at,
        ], fn ($v) => $v !== null);
    }
}
