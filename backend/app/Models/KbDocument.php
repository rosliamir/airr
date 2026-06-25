<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// M3 (FR-M3.2/M3.6) — an uploaded reference document and its ingestion status.
class KbDocument extends Model
{
    const STATUS_QUEUED = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_TRAINED = 'trained';
    const STATUS_ERROR = 'error';

    protected $fillable = [
        'knowledge_base_id', 'title', 'type', 'path', 'status',
        'error', 'chunk_count', 'trained_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['trained_at' => 'datetime', 'chunk_count' => 'integer'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class);
    }
}
