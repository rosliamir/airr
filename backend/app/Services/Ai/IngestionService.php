<?php

namespace App\Services\Ai;

use App\Models\KbChunk;
use App\Models\KbDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * M3 ingestion pipeline (FR-M3.2/M3.3/M3.6). Extract document text → structure-
 * aware chunk → persist chunks → mark trained. Embedding (FR-M3.5) is performed
 * here once Ollama + the pgvector column are available; until then chunks are
 * stored text-only and `embedding_pending` is reported.
 */
class IngestionService
{
    public function __construct(
        private readonly DocumentExtractor $extractor,
        private readonly ChunkerService $chunker,
        private readonly ModelResolver $resolver,
    ) {}

    /** Ingest one uploaded document. Returns the refreshed document. */
    public function ingest(KbDocument $document): KbDocument
    {
        $document->update(['status' => KbDocument::STATUS_PROCESSING, 'error' => null]);

        try {
            $absolute = Storage::disk('local')->path($document->path);
            $text = $this->extractor->extract($document->type, $absolute);
            $chunks = $this->chunker->chunk($text);

            DB::transaction(function () use ($document, $chunks) {
                $document->chunks()->delete(); // re-index safe
                foreach ($chunks as $i => $chunk) {
                    KbChunk::create([
                        'kb_document_id'    => $document->id,
                        'knowledge_base_id' => $document->knowledge_base_id,
                        'ordinal'           => $i,
                        'heading'           => $chunk['heading'],
                        'content'           => $chunk['content'],
                        'metadata'          => ['chars' => mb_strlen($chunk['content'])],
                    ]);
                }
                $document->update([
                    'status'      => KbDocument::STATUS_TRAINED,
                    'chunk_count' => count($chunks),
                    'trained_at'  => now(),
                ]);
            });
        } catch (Throwable $e) {
            $document->update(['status' => KbDocument::STATUS_ERROR, 'error' => $e->getMessage()]);
        }

        return $document->fresh();
    }

    /**
     * Embedding model that WILL be used for this KB's chunks, resolved through
     * the central resolver (FR-M15.8). Stored on the document's KB at ingest.
     */
    public function embeddingModel(KbDocument $document): string
    {
        return $this->resolver->model('embedding', $document->knowledgeBase?->project);
    }
}
