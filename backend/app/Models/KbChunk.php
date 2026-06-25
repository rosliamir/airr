<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// M3 (FR-M3.3/M3.5) — a structure-aware chunk of a document. The `embedding`
// vector column is added by the pgvector infra-gate migration; until then
// chunks are stored text-only, ready to be embedded.
class KbChunk extends Model
{
    protected $fillable = [
        'kb_document_id', 'knowledge_base_id', 'ordinal', 'heading', 'content', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'ordinal' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'kb_document_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
