<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Lookup;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// M14 — lookup/reference values. Active values are readable by any authenticated
// user (to populate dropdowns); CRUD + full listing require settings.manage.
class LookupController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    // Dropdown source: active values for a category (auth-only).
    public function index(Request $request): JsonResponse
    {
        $request->validate(['category' => 'required|string|max:60']);

        return $this->sendOk(
            Lookup::where('category', $request->input('category'))->where('is_active', true)
                ->orderBy('sort')->get(['id', 'value', 'label'])
        );
    }

    // Admin view: every lookup grouped by category (settings.manage).
    public function manage(): JsonResponse
    {
        return $this->sendOk(
            Lookup::orderBy('category')->orderBy('sort')->get()
                ->groupBy('category')
                ->map(fn ($items) => $items->map(fn ($l) => $this->row($l))->values())
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateLookup($request);
        $lookup = Lookup::create($data + ['is_system' => false]);
        $this->audit->log('lookup.created', Lookup::class, $lookup->id, null, $data);

        return $this->sendCreated($this->row($lookup));
    }

    public function update(Request $request, Lookup $lookup): JsonResponse
    {
        // System values: only label/sort/active editable, not the key.
        $data = $this->validateLookup($request, $lookup);
        if ($lookup->is_system) {
            unset($data['value'], $data['category']);
        }
        $lookup->update($data);
        $this->audit->log('lookup.updated', Lookup::class, $lookup->id);

        return $this->sendOk($this->row($lookup));
    }

    public function destroy(Lookup $lookup): JsonResponse
    {
        if ($lookup->is_system) {
            return $this->sendError(422, 'SYSTEM_LOOKUP', 'System values cannot be deleted (deactivate instead).');
        }
        $lookup->delete();
        $this->audit->log('lookup.deleted', Lookup::class, $lookup->id);

        return $this->sendNoContent();
    }

    private function validateLookup(Request $request, ?Lookup $lookup = null): array
    {
        return $request->validate([
            'category'  => 'required|string|max:60',
            'value'     => 'required|string|max:60',
            'label'     => 'required|string|max:120',
            'sort'      => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function row(Lookup $l): array
    {
        return [
            'id' => $l->id, 'category' => $l->category, 'value' => $l->value,
            'label' => $l->label, 'sort' => $l->sort, 'is_active' => $l->is_active, 'is_system' => $l->is_system,
        ];
    }
}
