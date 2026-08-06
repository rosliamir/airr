<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Dashboard;
use App\Services\AuditService;
use App\Services\Reporting\DashboardRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard Authoring — widget-based dashboards (report/text/task/announcement).
// Read gated by dashboards.view; create/edit/delete/run by their own permissions.
// Owner + per-dashboard ACL visibility scoping, mirrors ReportController.
class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditService $audit,
        protected DashboardRenderer $renderer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Dashboard::query()->with('creator:id,name');

        $viewer = $request->user();
        if (! $viewer->seesEverything()) {
            $query->accessibleTo($viewer);
        }

        return $this->sendOk($query->orderByDesc('updated_at')->get()->map(fn ($d) => $this->row($d)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:160',
            'description' => 'nullable|string|max:1000',
            'definition'  => 'nullable|array',
        ]);

        $dashboard = Dashboard::create([
            ...$data,
            'status'     => Dashboard::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->log('dashboard.created', Dashboard::class, $dashboard->id);

        return $this->sendCreated($this->row($dashboard->fresh()));
    }

    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeDashboard($request, $dashboard, 'view');

        return $this->sendOk($this->row($dashboard));
    }

    public function update(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeDashboard($request, $dashboard, 'edit');

        $data = $request->validate([
            'name'        => 'required|string|max:160',
            'description' => 'nullable|string|max:1000',
            'definition'  => 'required|array',
        ]);

        $dashboard->update($data);
        $this->audit->log('dashboard.updated', Dashboard::class, $dashboard->id);

        return $this->sendOk($this->row($dashboard->fresh()));
    }

    public function destroy(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeDashboard($request, $dashboard, 'edit');

        $dashboard->delete();
        $this->audit->log('dashboard.deleted', Dashboard::class, $dashboard->id);

        return $this->sendNoContent();
    }

    public function run(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeDashboard($request, $dashboard, 'run');

        $html = $this->renderer->render($dashboard);

        return $this->sendOk(['html' => $html]);
    }

    public function permissions(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeDashboard($request, $dashboard, 'edit');

        return $this->sendOk($dashboard->roles->map(fn ($role) => [
            'role_id' => $role->id,
            'name'    => $role->name,
            'view'    => (bool) $role->pivot->can_view,
            'edit'    => (bool) $role->pivot->can_edit,
            'run'     => (bool) $role->pivot->can_run,
        ]));
    }

    public function syncPermissions(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeDashboard($request, $dashboard, 'edit');

        $data = $request->validate([
            'permissions'            => 'nullable|array',
            'permissions.*.role_id'  => 'required|integer|exists:roles,id',
            'permissions.*.view'     => 'boolean',
            'permissions.*.edit'     => 'boolean',
            'permissions.*.run'      => 'boolean',
        ]);

        $dashboard->syncPermissions($data['permissions'] ?? []);

        return $this->sendOk($dashboard->fresh('roles')->roles->map(fn ($role) => [
            'role_id' => $role->id,
            'name'    => $role->name,
            'view'    => (bool) $role->pivot->can_view,
            'edit'    => (bool) $role->pivot->can_edit,
            'run'     => (bool) $role->pivot->can_run,
        ]));
    }

    private function authorizeDashboard(Request $request, Dashboard $dashboard, string $ability): void
    {
        if (! $dashboard->allows($request->user(), $ability)) {
            abort(response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => "You do not have {$ability} access to this dashboard."],
            ], 403));
        }
    }

    private function row(Dashboard $d): array
    {
        return [
            'id'          => $d->id,
            'name'        => $d->name,
            'description' => $d->description,
            'definition'  => $d->definition ?? [],
            'status'      => $d->status,
            'creator'     => $d->creator ? ['id' => $d->creator->id, 'name' => $d->creator->name] : null,
            'created_at'  => $d->created_at,
            'updated_at'  => $d->updated_at,
        ];
    }
}
