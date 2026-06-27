<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Project;
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
        $query = Project::query()->withCount(['users'])->with('creator:id,name');

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

        return $this->sendOk($query->orderByDesc('created_at')->get()->map(fn (Project $p) => $this->row($p)));
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
        $project->delete(); // pivots cascade
        $this->audit->log('project.deleted', Project::class, $project->id);

        return $this->sendNoContent();
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
            'creator'     => $p->creator ? ['id' => $p->creator->id, 'name' => $p->creator->name] : null,
            'users_count' => $p->users_count ?? $p->users()->count(),
            // Detail-only (present when loaded):
            'users'  => $p->relationLoaded('users') ? $p->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]) : null,
        ];
    }
}
