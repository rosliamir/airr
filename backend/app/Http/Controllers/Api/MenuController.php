<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Menu;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// M14 — DB-driven navigation. nav() builds the permission-filtered tree the SPA
// renders; CRUD (menus.manage) lets admins manage the menu.
class MenuController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    // The current user's visible menu tree (any authenticated user).
    public function nav(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = Menu::where('is_active', true)->orderBy('sort')->get();

        $tree = $all->whereNull('parent_id')->map(function (Menu $m) use ($all, $user) {
            $children = $all->where('parent_id', $m->id)
                ->filter(fn ($c) => $this->canSee($c, $user))
                ->map(fn ($c) => $this->node($c))
                ->values();

            // A heading (no route) with no visible children is hidden.
            if (! $m->route && $children->isEmpty()) {
                return null;
            }
            if ($m->route && ! $this->canSee($m, $user)) {
                return null;
            }

            return [...$this->node($m), 'children' => $children];
        })->filter()->values();

        return $this->sendOk($tree);
    }

    // Full flat list for the admin editor (menus.manage).
    public function index(): JsonResponse
    {
        return $this->sendOk(Menu::orderBy('parent_id')->orderBy('sort')->get()->map(fn ($m) => $this->node($m, true)));
    }

    public function store(Request $request): JsonResponse
    {
        $menu = Menu::create($this->validateMenu($request));
        $this->audit->log('menu.created', Menu::class, $menu->id, null, ['label' => $menu->label]);

        return $this->sendCreated($this->node($menu, true));
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        $menu->update($this->validateMenu($request, $menu));
        $this->audit->log('menu.updated', Menu::class, $menu->id);

        return $this->sendOk($this->node($menu, true));
    }

    public function destroy(Menu $menu): JsonResponse
    {
        $menu->delete(); // children detach (nullOnDelete)
        $this->audit->log('menu.deleted', Menu::class, $menu->id);

        return $this->sendNoContent();
    }

    private function canSee(Menu $m, User $user): bool
    {
        if ($m->permission && ! $user->seesEverything() && ! $user->hasPermission($m->permission)) {
            return false;
        }
        if ($m->feature && ! Edition::allows($m->feature)) {
            return false;
        }

        return true;
    }

    private function validateMenu(Request $request, ?Menu $menu = null): array
    {
        return $request->validate([
            'parent_id'  => 'nullable|integer|exists:menus,id',
            'label'      => 'required|string|max:80',
            'route'      => 'nullable|string|max:80',
            'icon'       => 'nullable|string|max:40',
            'module'     => 'nullable|string|max:10',
            'permission' => 'nullable|string|max:60',
            'feature'    => 'nullable|string|max:60',
            'sort'       => 'nullable|integer',
            'is_active'  => 'nullable|boolean',
        ]);
    }

    private function node(Menu $m, bool $admin = false): array
    {
        $base = [
            'id'     => $m->id,
            'label'  => $m->label,
            'route'  => $m->route,
            'icon'   => $m->icon,
            'module' => $m->module,
        ];
        if ($admin) {
            $base += [
                'parent_id'  => $m->parent_id,
                'permission' => $m->permission,
                'feature'    => $m->feature,
                'sort'       => $m->sort,
                'is_active'  => $m->is_active,
            ];
        }

        return $base;
    }
}
