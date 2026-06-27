<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\KbDocument;
use App\Models\KnowledgeBase;
use App\Models\DataSource;
use App\Services\Ai\DocumentExtractor;
use App\Services\Ai\IngestionService;
use App\Services\Ai\SystemKnowledgeService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// M3 (FR-M3.2/M3.6) — upload reference documents into a KB and run the
// ingestion pipeline (extract → chunk → store). kb.manage for writes.
class KbDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditService $audit,
        protected IngestionService $ingestion,
        protected SystemKnowledgeService $system,
    ) {}

    public function index(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $docs = $knowledgeBase->documents()->orderByDesc('created_at')->get();

        return $this->sendOk($docs->map(fn ($d) => $this->row($d)));
    }

    // FR-M3.1 — upload a document, then ingest it synchronously.
    public function store(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $categories = array_keys(config('kb.document_categories', []));
        $validated = $request->validate([
            'file'           => 'required|file|mimes:pdf,docx,xlsx,xls,md,markdown,txt|max:20480',
            'category'       => ['nullable', Rule::in($categories)],
            'category_label' => 'nullable|string|max:80', // custom label when category = other
        ]);

        $upload = $request->file('file');
        $type = DocumentExtractor::detectType($upload->getClientOriginalName());
        if (! $type) {
            return $this->sendError(422, 'UNSUPPORTED_TYPE', 'Unsupported document type.');
        }

        $category = $validated['category'] ?? 'other';
        $path = $upload->store('kb_documents', 'local');
        $document = $knowledgeBase->documents()->create([
            'title'          => $upload->getClientOriginalName(),
            'type'           => $type,
            'category'       => $category,
            'category_label' => $category === 'other' ? ($validated['category_label'] ?? null) : null,
            'source_kind'    => KbDocument::SOURCE_UPLOAD,
            'path'           => $path,
            'status'         => KbDocument::STATUS_QUEUED,
            'created_by'     => $request->user()->id,
        ]);

        // Record the embedding model that will apply (resolved centrally), then ingest.
        $knowledgeBase->update(['embedding_model' => $this->ingestion->embeddingModel($document)]);
        $document = $this->ingestion->ingest($document);
        $document->snapshotVersion($request->user()->id, 'Initial upload'); // FR-M3.9 v1

        $this->audit->log('kb.document_ingested', KbDocument::class, $document->id, null, [
            'kb' => $knowledgeBase->id, 'status' => $document->status, 'chunks' => $document->chunk_count,
        ]);

        return $this->sendCreated($this->row($document));
    }

    // FR-M3.9 — upload a NEW VERSION of an existing document. Chunks are rebuilt
    // from the new file; the prior version stays in the history log.
    public function addVersion(Request $request, KbDocument $kbDocument): JsonResponse
    {
        if ($kbDocument->source_kind !== KbDocument::SOURCE_UPLOAD) {
            return $this->sendError(422, 'NOT_VERSIONABLE', 'Only uploaded documents support file versions.');
        }
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,docx,xlsx,xls,md,markdown,txt|max:20480',
            'note' => 'nullable|string|max:200',
        ]);

        $upload = $request->file('file');
        $type = DocumentExtractor::detectType($upload->getClientOriginalName());
        if (! $type) {
            return $this->sendError(422, 'UNSUPPORTED_TYPE', 'Unsupported document type.');
        }

        $kbDocument->update([
            'title'   => $upload->getClientOriginalName(),
            'type'    => $type,
            'path'    => $upload->store('kb_documents', 'local'),
            'version' => $kbDocument->version + 1,
        ]);
        $kbDocument = $this->ingestion->ingest($kbDocument);
        $kbDocument->snapshotVersion($request->user()->id, $validated['note'] ?? null);

        $this->audit->log('kb.document_versioned', KbDocument::class, $kbDocument->id, null, [
            'version' => $kbDocument->version, 'status' => $kbDocument->status, 'chunks' => $kbDocument->chunk_count,
        ]);

        return $this->sendCreated($this->row($kbDocument));
    }

    // FR-M3.9 — version history (the change log) for a document.
    public function versions(KbDocument $kbDocument): JsonResponse
    {
        return $this->sendOk($kbDocument->versions()->with('author:id,name')->get()->map(fn ($v) => [
            'version'     => $v->version,
            'title'       => $v->title,
            'type'        => $v->type,
            'status'      => $v->status,
            'chunk_count' => $v->chunk_count,
            'note'        => $v->note,
            'author'      => $v->author?->name,
            'created_at'  => $v->created_at,
        ]));
    }

    // FR-M3.2b — ingest AIRR's own system knowledge (DB schema, RBAC, …) into
    // the KB as descriptive text. No file upload; source_kind = system.
    public function ingestSystem(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $data = $request->validate([
            'source'         => ['required', Rule::in(array_keys(config('kb.system_sources', [])))],
            'data_source_id' => 'nullable|integer|exists:data_sources,id',
        ]);

        [$title, $text] = match ($data['source']) {
            'db_schema' => $this->dbSchemaPayload($data['data_source_id'] ?? null),
            'rbac'      => ['System knowledge: RBAC model', $this->system->rbac()],
            default     => [null, null],
        };

        if ($text === null) {
            return $this->sendError(422, 'UNSUPPORTED_SOURCE', 'This system source is not available yet.');
        }

        $document = $knowledgeBase->documents()->create([
            'title'       => $title,
            'type'        => 'markdown',
            'category'    => $data['source'],
            'source_kind' => KbDocument::SOURCE_SYSTEM,
            'status'      => KbDocument::STATUS_QUEUED,
            'created_by'  => $request->user()->id,
        ]);
        $knowledgeBase->update(['embedding_model' => $this->ingestion->embeddingModel($document)]);
        $document = $this->ingestion->ingestText($document, $text);
        $document->snapshotVersion($request->user()->id, 'Ingested from system source');

        $this->audit->log('kb.system_ingested', KbDocument::class, $document->id, null, [
            'kb' => $knowledgeBase->id, 'source' => $data['source'], 'chunks' => $document->chunk_count,
        ]);

        return $this->sendCreated($this->row($document));
    }

    /** @return array{0:?string,1:?string} [title, text] */
    private function dbSchemaPayload(?int $dataSourceId): array
    {
        if (! $dataSourceId) {
            return [null, null];
        }
        $source = DataSource::find($dataSourceId);
        if (! $source) {
            return [null, null];
        }

        return ["System knowledge: schema of {$source->name}", $this->system->dbSchema($source)];
    }

    // FR-M3.9 — re-index an existing document (re-extract + re-chunk).
    public function reindex(KbDocument $kbDocument): JsonResponse
    {
        // System/integration docs have no stored file — re-ingest from the source.
        if ($kbDocument->source_kind !== KbDocument::SOURCE_UPLOAD || ! $kbDocument->path) {
            return $this->sendError(422, 'REINDEX_UNSUPPORTED', 'Re-ingest this item from its system source instead.');
        }
        $document = $this->ingestion->ingest($kbDocument);
        $this->audit->log('kb.document_reindexed', KbDocument::class, $document->id, null, ['status' => $document->status]);

        return $this->sendOk($this->row($document));
    }

    public function destroy(KbDocument $kbDocument): JsonResponse
    {
        if ($kbDocument->path) {
            Storage::disk('local')->delete($kbDocument->path);
        }
        $kbDocument->delete(); // chunks cascade
        $this->audit->log('kb.document_deleted', KbDocument::class, $kbDocument->id);

        return $this->sendNoContent();
    }

    private function row(KbDocument $d): array
    {
        $categories = config('kb.document_categories', []);
        $categoryName = $d->category === 'other' && $d->category_label
            ? $d->category_label
            : ($categories[$d->category] ?? $d->category);

        return [
            'id'             => $d->id,
            'title'          => $d->title,
            'type'           => $d->type,
            'category'       => $d->category,
            'category_label' => $categoryName, // resolved display label
            'source_kind'    => $d->source_kind,
            'version'        => $d->version,
            'status'         => $d->status,
            'chunk_count'    => $d->chunk_count,
            'error'          => $d->error,
            'trained_at'     => $d->trained_at,
            'created_at'     => $d->created_at,
        ];
    }
}
