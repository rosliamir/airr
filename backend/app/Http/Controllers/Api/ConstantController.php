<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Constant;
use App\Models\Dataset;
use App\Services\AuditService;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $data = $this->handleImageUpload($request, $data);
        $constant = Constant::create($data + ['created_by' => $request->user()->id]);
        $this->audit->log('constant.created', Constant::class, $constant->id);
        $this->saveHistory($constant, $request->user()->id, 'created');

        return $this->sendCreated($this->row($constant));
    }

    public function update(Request $request, Constant $constant): JsonResponse
    {
        if ($constant->scope === Constant::SCOPE_SYSTEM && ! $request->has('data_source_id') && ! $request->has('type')) {
            // System constants: only allow updating format/label (fixed built-ins).
            $constant->update(['format' => $request->input('format'), 'label' => $request->input('label', $constant->label)]);
        } else {
            $data = $this->validateConstant($request, $constant);
            $data = $this->handleImageUpload($request, $data, $constant);
            $constant->update($data);
        }
        $this->audit->log('constant.updated', Constant::class, $constant->id);
        $this->saveHistory($constant, $request->user()->id, 'updated');

        return $this->sendOk($this->row($constant));
    }

    public function destroy(Constant $constant): JsonResponse
    {
        if ($constant->scope === Constant::SCOPE_SYSTEM) {
            return $this->sendError(403, 'SYSTEM_CONSTANT', 'System constants cannot be deleted.');
        }
        if ($constant->image_path) {
            Storage::disk('local')->delete($constant->image_path);
        }
        $constant->delete();
        $this->audit->log('constant.deleted', Constant::class, $constant->id);

        return $this->sendNoContent();
    }

    // Change history snapshots.
    public function history(Constant $constant): JsonResponse
    {
        $rows = DB::table('constant_histories as h')
            ->join('users as u', 'u.id', '=', 'h.changed_by')
            ->where('h.constant_id', $constant->id)
            ->orderByDesc('h.created_at')
            ->limit(50)
            ->get(['h.id', 'h.action', 'h.snapshot', 'h.created_at', 'u.name as changed_by_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'              => $r->id,
            'action'          => $r->action,
            'snapshot'        => json_decode($r->snapshot, true),
            'changed_by_name' => $r->changed_by_name,
            'created_at'      => $r->created_at,
        ]));
    }

    private function validateConstant(Request $request, ?Constant $existing = null): array
    {
        $data = $request->validate([
            'scope'          => ['required', Rule::in(['system', 'global', 'project'])],
            'type'           => ['required', Rule::in([Constant::TYPE_TEXT, Constant::TYPE_DATA, Constant::TYPE_IMAGE, Constant::TYPE_CALC])],
            'project_id'     => 'nullable|integer|exists:projects,id',
            'key'            => 'required|string|max:80|regex:/^[A-Z0-9_]+$/',
            'label'          => 'required|string|max:160',
            'value'          => 'nullable|string',
            'format'         => 'nullable|string|max:80',
            'sort'           => 'nullable|integer',
            'data_source_id' => 'required_if:type,' . Constant::TYPE_DATA . '|nullable|integer|exists:data_sources,id',
            'dataset_id'     => 'required_if:type,' . Constant::TYPE_DATA . '|nullable|integer|exists:datasets,id',
            'data_column'    => 'required_if:type,' . Constant::TYPE_DATA . '|nullable|string|max:120',
            'formula'        => 'required_if:type,' . Constant::TYPE_CALC . '|nullable|string|max:500',
            'image'          => 'nullable|image|max:5120',
        ]);

        if (($data['scope'] ?? null) === Constant::SCOPE_PROJECT && ! Edition::allows('project_constants')) {
            abort(403, 'Project-level constants require a Standard or Enterprise edition.');
        }

        if (($data['type'] ?? null) === Constant::TYPE_DATA && ! empty($data['dataset_id'])) {
            $dataset = Dataset::find($data['dataset_id']);
            $columns = collect($dataset?->fields ?? [])->pluck('name')->filter()->all();
            if ($columns && ! in_array($data['data_column'], $columns, true)) {
                abort(422, "Column [{$data['data_column']}] is not part of dataset #{$data['dataset_id']}'s fields.");
            }
        }

        unset($data['image']);

        return $data;
    }

    private function handleImageUpload(Request $request, array $data, ?Constant $existing = null): array
    {
        if (($data['type'] ?? null) !== Constant::TYPE_IMAGE) {
            return $data;
        }

        if ($request->hasFile('image')) {
            if ($existing?->image_path) {
                Storage::disk('local')->delete($existing->image_path);
            }
            $data['image_path'] = $request->file('image')->store('constant_images', 'local');
        }

        return $data;
    }

    private function saveHistory(Constant $c, int $userId, string $action): void
    {
        DB::table('constant_histories')->insert([
            'constant_id' => $c->id,
            'changed_by'  => $userId,
            'action'      => $action,
            'snapshot'    => json_encode([
                'scope'          => $c->scope,
                'type'           => $c->type,
                'project_id'     => $c->project_id,
                'key'            => $c->key,
                'label'          => $c->label,
                'value'          => $c->value,
                'format'         => $c->format,
                'data_source_id' => $c->data_source_id,
                'dataset_id'     => $c->dataset_id,
                'data_column'    => $c->data_column,
                'image_path'     => $c->image_path,
                'formula'        => $c->formula,
            ]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function row(Constant $c): array
    {
        return array_filter([
            'id'             => $c->id,
            'scope'          => $c->scope,
            'type'           => $c->type,
            'project_id'     => $c->project_id,
            'key'            => $c->key,
            'label'          => $c->label,
            'value'          => $c->value,
            'format'         => $c->format,
            'sort'           => $c->sort,
            'data_source_id' => $c->data_source_id,
            'dataset_id'     => $c->dataset_id,
            'data_column'    => $c->data_column,
            'formula'        => $c->formula,
            'image_url'      => $c->image_path ? route('constants.image', $c->id) : null,
            'placeholder'    => '{{' . strtoupper($c->scope) . ':' . $c->key . '}}',
        ], fn ($v) => $v !== null);
    }

    // Serves the stored image via a controller (local disk isn't publicly exposed).
    public function image(Constant $constant)
    {
        abort_unless($constant->image_path && Storage::disk('local')->exists($constant->image_path), 404);

        return Storage::disk('local')->response($constant->image_path);
    }
}
