<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\KbDocument;
use App\Models\KnowledgeBase;
use App\Services\Ai\DocumentExtractor;
use App\Services\Ai\IngestionService;
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

        $this->audit->log('kb.document_ingested', KbDocument::class, $document->id, null, [
            'kb' => $knowledgeBase->id, 'status' => $document->status, 'chunks' => $document->chunk_count,
        ]);

        return $this->sendCreated($this->row($document));
    }

    // FR-M3.9 — re-index an existing document (re-extract + re-chunk).
    public function reindex(KbDocument $kbDocument): JsonResponse
    {
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
            'status'         => $d->status,
            'chunk_count'    => $d->chunk_count,
            'error'          => $d->error,
            'trained_at'     => $d->trained_at,
            'created_at'     => $d->created_at,
        ];
    }
}
