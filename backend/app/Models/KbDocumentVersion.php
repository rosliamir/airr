<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// M3 (FR-M3.9) — an immutable record of one document version (the change log).
class KbDocumentVersion extends Model
{
    protected $fillable = [
        'kb_document_id', 'version', 'title', 'type', 'path',
        'status', 'chunk_count', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'chunk_count' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'kb_document_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
