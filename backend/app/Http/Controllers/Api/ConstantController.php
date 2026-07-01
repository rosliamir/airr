<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Constant;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConstantController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Constant::query()->orderBy('scope')->orderBy('sort')->orderBy('key');

        if ($scope = $request->input('scope')) {
            $query->where('scope', $scope);
        }
        if ($pid = $request->input('project_id')) {
            $query->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $pid));
        }

        return $this->sendOk($query->get()->map(fn ($c) => $this->row($c)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateConstant($request);
        $constant = Constant::create($data + ['created_by' => $request->user()->id]);
        $this->audit->log('constant.created', Constant::class, $constant->id);

        return $this->sendCreated($this->row($constant));
    }

    public function update(Request $request, Constant $constant): JsonResponse
    {
        if ($constant->scope === Constant::SCOPE_SYSTEM && ! $request->has('format')) {
            // System constants: only allow updating format.
            $constant->update(['format' => $request->input('format'), 'label' => $request->input('label', $constant->label)]);
        } else {
            $constant->update($this->validateConstant($request, $constant));
        }
        $this->audit->log('constant.updated', Constant::class, $constant->id);

        return $this->sendOk($this->row($constant));
    }

    public function destroy(Constant $constant): JsonResponse
    {
        if ($constant->scope === Constant::SCOPE_SYSTEM) {
            return $this->sendError(403, 'SYSTEM_CONSTANT', 'System constants cannot be deleted.');
        }
        $constant->delete();
        $this->audit->log('constant.deleted', Constant::class, $constant->id);

        return $this->sendNoContent();
    }

    private function validateConstant(Request $request, ?Constant $existing = null): array
    {
        return $request->validate([
            'scope'      => ['required', Rule::in(['global', 'project'])],
            'project_id' => 'nullable|integer|exists:projects,id',
            'key'        => 'required|string|max:80|regex:/^[A-Z0-9_]+$/',
            'label'      => 'required|string|max:160',
            'value'      => 'nullable|string',
            'format'     => 'nullable|string|max:80',
            'sort'       => 'nullable|integer',
        ]);
    }

    private function row(Constant $c): array
    {
        return [
            'id'         => $c->id,
            'scope'      => $c->scope,
            'project_id' => $c->project_id,
            'key'        => $c->key,
            'label'      => $c->label,
            'value'      => $c->value,
            'format'     => $c->format,
            'sort'       => $c->sort,
            'placeholder'=> '{{' . strtoupper($c->scope) . ':' . $c->key . '}}',
        ];
    }
}
