<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\KnowledgeBase;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// M3 (FR-M3.1/M3.9) — knowledge base administration. Read gated by kb.view,
// writes by kb.manage. Owner/project visibility mirrors data sources.
class KnowledgeBaseController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = KnowledgeBase::query()
            ->with('project:id,code,name')
            ->withCount(['documents', 'chunks']);

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

        return $this->sendOk($query->orderByDesc('created_at')->get()->map(fn ($kb) => $this->row($kb)));
    }

    // Document category taxonomy for the upload UI (FR-M3.2).
    public function categories(): JsonResponse
    {
        return $this->sendOk([
            'documents' => config('kb.document_categories', []),
            'system'    => config('kb.system_sources', []),
        ]);
    }

    public function show(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $knowledgeBase->load(['project:id,code,name', 'documents' => fn ($q) => $q->orderByDesc('created_at')]);

        return $this->sendOk($this->row($knowledgeBase, true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateKb($request);
        $kb = KnowledgeBase::create([
            ...$data,
            'version'    => 1,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->log('kb.created', KnowledgeBase::class, $kb->id, null, ['name' => $kb->name]);

        return $this->sendCreated($this->row($kb->fresh('project')));
    }

    public function update(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $data = $this->validateKb($request);
        $knowledgeBase->update($data);
        $this->audit->log('kb.updated', KnowledgeBase::class, $knowledgeBase->id);

        return $this->sendOk($this->row($knowledgeBase->fresh('project')));
    }

    public function destroy(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $knowledgeBase->delete(); // documents + chunks cascade
        $this->audit->log('kb.deleted', KnowledgeBase::class, $knowledgeBase->id);

        return $this->sendNoContent();
    }

    private function validateKb(Request $request): array
    {
        return $request->validate([
            'project_id'  => 'nullable|integer|exists:projects,id',
            'name'        => 'required|string|max:160',
            'description' => 'nullable|string|max:1000',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:40',
            'clearance'   => 'nullable|string|max:40',
        ]);
    }

    private function row(KnowledgeBase $kb, bool $withDocuments = false): array
    {
        return array_filter([
            'id'              => $kb->id,
            'name'            => $kb->name,
            'description'     => $kb->description,
            'tags'            => $kb->tags ?? [],
            'version'         => $kb->version,
            'clearance'       => $kb->clearance,
            'embedding_model' => $kb->embedding_model,
            'project'         => $kb->project ? ['id' => $kb->project->id, 'code' => $kb->project->code, 'name' => $kb->project->name] : null,
            'documents_count' => $kb->documents_count ?? $kb->documents()->count(),
            'chunks_count'    => $kb->chunks_count ?? $kb->chunks()->count(),
            'documents'       => $withDocuments ? $kb->documents->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->title, 'type' => $d->type,
                'status' => $d->status, 'chunk_count' => $d->chunk_count,
                'error' => $d->error, 'trained_at' => $d->trained_at,
            ]) : null,
            'created_at'      => $kb->created_at,
        ], fn ($v) => $v !== null);
    }
}
