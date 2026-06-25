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

    // How the item entered the KB.
    const SOURCE_UPLOAD = 'upload';        // user-uploaded file
    const SOURCE_INTEGRATION = 'integration'; // pulled from an external system (e.g. helpdesk)
    const SOURCE_SYSTEM = 'system';        // introspected from AIRR itself (schema/menu/rbac…)

    protected $fillable = [
        'knowledge_base_id', 'title', 'type', 'category', 'category_label', 'source_kind',
        'path', 'status', 'error', 'chunk_count', 'trained_at', 'created_by',
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
