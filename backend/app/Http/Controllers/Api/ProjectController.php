<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// FR-M14: project administration. Reports are stored under a project.
// Read gated by projects.view; writes by projects.manage.
class ProjectController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Project::query()->withCount(['users', 'reports'])->with('creator:id,name');

        // Owner+membership scoping — admins see everything.
        $viewer = $request->user();
        if (! $viewer->seesEverything()) {
            $query->visibleTo($viewer);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        $sort = $request->input('sort', '-created_at');
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        if (in_array($column, ['name', 'created_at', 'status'], true)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderByDesc('created_at');
        }

        return $this->sendOk($query->get()->map(fn (Project $p) => $this->row($p)));
    }

    public function archive(Project $project): JsonResponse
    {
        $project->update(['archived_at' => now()]);
        $this->audit->log('project.archived', Project::class, $project->id);

        return $this->sendOk($this->row($project->fresh(['creator'])));
    }

    public function unarchive(Project $project): JsonResponse
    {
        $project->update(['archived_at' => null]);
        $this->audit->log('project.unarchived', Project::class, $project->id);

        return $this->sendOk($this->row($project->fresh(['creator'])));
    }

    // Clone a project (settings only — members/reports are NOT copied, to avoid
    // silently duplicating access grants across projects).
    public function duplicate(Request $request, Project $project): JsonResponse
    {
        $copy = Project::create([
            'code' => $project->code . '-COPY-' . substr(md5((string) microtime(true)), 0, 4),
            'name' => $project->name . ' (Copy)',
            'customer_name' => $project->customer_name, 'type' => $project->type, 'color' => $project->color,
            'status' => Project::STATUS_ACTIVE, 'description' => $project->description,
            'tags' => $project->tags, 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('project.duplicated', Project::class, $copy->id, null, ['from' => $project->id]);

        return $this->sendCreated($this->row($copy->fresh(['creator'])));
    }

    public function export(Project $project): \Symfony\Component\HttpFoundation\Response
    {
        $payload = [
            'code' => $project->code, 'name' => $project->name, 'customer_name' => $project->customer_name,
            'type' => $project->type, 'color' => $project->color, 'description' => $project->description,
            'tags' => $project->tags ?? [], 'exported_at' => now()->toIso8601String(),
        ];
        $filename = \Illuminate\Support\Str::slug($project->name) . '.json';

        return response()->json($payload)->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => 'nullable|file|mimes:json,txt|max:2048', 'payload' => 'nullable|array']);
        if ($request->hasFile('file')) {
            $decoded = json_decode($request->file('file')->get(), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return $this->sendError(422, 'INVALID_JSON', 'The uploaded file is not valid JSON.');
            }
        } else {
            $decoded = $data['payload'] ?? null;
        }
        if (! $decoded || empty($decoded['name']) || empty($decoded['code'])) {
            return $this->sendError(422, 'INVALID_PAYLOAD', 'Provide a file or payload with at least "name" and "code".');
        }

        $code = $decoded['code'];
        if (Project::where('code', $code)->exists()) {
            $code .= '-' . substr(md5((string) microtime(true)), 0, 4);
        }
        $project = Project::create([
            'code' => $code, 'name' => $decoded['name'], 'customer_name' => $decoded['customer_name'] ?? null,
            'type' => $decoded['type'] ?? null, 'color' => $decoded['color'] ?? null,
            'description' => $decoded['description'] ?? null, 'tags' => $decoded['tags'] ?? [],
            'status' => Project::STATUS_ACTIVE, 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('project.imported', Project::class, $project->id);

        return $this->sendCreated($this->row($project->fresh(['creator'])));
    }

    public function logs(Project $project): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.object_type', Project::class)
            ->where('a.object_id', (string) $project->id)
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

    public function show(Project $project): JsonResponse
    {
        return $this->sendOk($this->row($project->load(['users:id,name,email', 'creator:id,name'])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateProject($request);
        $project = Project::create($data + ['created_by' => $request->user()->id]);
        $this->syncMembers($project, $request);
        $this->audit->log('project.created', Project::class, $project->id, null, $data);

        return $this->sendCreated($this->row($project->load(['users:id,name,email', 'creator:id,name'])));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $data = $this->validateProject($request, $project);
        $project->update($data);
        $this->syncMembers($project, $request);
        $this->audit->log('project.updated', Project::class, $project->id, null, $data);

        return $this->sendOk($this->row($project->load(['users:id,name,email', 'creator:id,name'])));
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete(); // soft delete — pivots stay intact, restorable via restore()
        $this->audit->log('project.deleted', Project::class, $project->id);

        return $this->sendNoContent();
    }

    public function restore(int $id): JsonResponse
    {
        $project = Project::withTrashed()->findOrFail($id);
        $project->restore();
        $this->audit->log('project.restored', Project::class, $project->id);

        return $this->sendOk($this->row($project->fresh(['creator'])));
    }

    private function validateProject(Request $request, ?Project $project = null): array
    {
        return $request->validate([
            'code'          => ['required', 'string', 'max:40', Rule::unique('projects', 'code')->ignore($project?->id)],
            'name'          => 'required|string|max:160',
            'customer_name' => 'nullable|string|max:160',
            'type'          => 'nullable|string|max:60',
            'color'         => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'status'        => ['nullable', Rule::in(Project::STATUSES)],
            'start_date'    => 'nullable|date',
            'end_date'      => 'nullable|date|after_or_equal:start_date',
            'description'   => 'nullable|string|max:2000',
            'tags'          => 'nullable|array',
            'tags.*'        => 'string|max:40',
            // FR-M14.5 per-project AI override (edition-gated at resolution time).
            'ai_config'                   => 'nullable|array',
            'ai_config.provider'          => 'nullable|string|max:40',
            'ai_config.models'            => 'nullable|array',
            'ai_config.models.embedding'  => 'nullable|string|max:120',
            'ai_config.models.generation' => 'nullable|string|max:120',
            'ai_config.models.reasoning'  => 'nullable|string|max:120',
            'ai_config.models.audit'      => 'nullable|string|max:120',
        ]);
    }

    // --- Quick link management from the project card (FR-M6/M14) ---

    // Reports: all reports + which are linked to this project. A report can be
    // linked to many projects.
    public function reportLinks(Project $project): JsonResponse
    {
        return $this->sendOk([
            'all'    => Report::orderBy('name')->get(['id', 'name', 'type'])
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'type' => $r->type]),
            'linked' => $project->reports()->pluck('reports.id'),
        ]);
    }

    public function syncReports(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['report_ids' => 'array', 'report_ids.*' => 'integer|exists:reports,id']);
        $project->reports()->sync($data['report_ids'] ?? []);
        $this->audit->log('project.reports_synced', Project::class, $project->id, null, ['count' => count($data['report_ids'] ?? [])]);

        return $this->sendOk(['linked' => $project->reports()->pluck('reports.id')]);
    }

    public function userLinks(Project $project): JsonResponse
    {
        return $this->sendOk([
            'all'    => User::orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            'linked' => $project->users()->pluck('users.id'),
        ]);
    }

    public function syncUsers(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['user_ids' => 'array', 'user_ids.*' => 'integer|exists:users,id']);
        $project->users()->sync($data['user_ids'] ?? []);
        $this->audit->log('project.users_synced', Project::class, $project->id, null, ['count' => count($data['user_ids'] ?? [])]);

        return $this->sendOk(['linked' => $project->users()->pluck('users.id')]);
    }

    private function syncMembers(Project $project, Request $request): void
    {
        $request->validate([
            'users'    => 'array',
            'users.*'  => 'integer|exists:users,id',
        ]);
        if ($request->has('users')) {
            $project->users()->sync($request->input('users', []));
        }
    }

    private function row(Project $p): array
    {
        return [
            'id'          => $p->id,
            'code'        => $p->code,
            'name'        => $p->name,
            'customer_name' => $p->customer_name,
            'type'        => $p->type,
            'color'       => $p->color,
            'status'      => $p->status,
            'start_date'  => $p->start_date?->toDateString(),
            'end_date'    => $p->end_date?->toDateString(),
            'description' => $p->description,
            'ai_config'   => $p->ai_config,
            'tags'        => $p->tags ?? [],
            'archived_at' => $p->archived_at?->toIso8601String(),
            'creator'     => $p->creator ? ['id' => $p->creator->id, 'name' => $p->creator->name] : null,
            'deleted_at'  => $p->deleted_at?->toIso8601String(),
            'users_count'   => $p->users_count ?? $p->users()->count(),
            'reports_count' => $p->reports_count ?? $p->reports()->count(),
            'templates_count' => 0, // Templates module not built yet (M6)
            // Detail-only (present when loaded):
            'users'  => $p->relationLoaded('users') ? $p->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]) : null,
        ];
    }
}
