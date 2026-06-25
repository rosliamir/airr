<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// M3 (FR-M3.1/M3.9) — a knowledge base / collection of reference documents,
// versioned and taggable. Ownership/project scoping mirrors data sources.
class KnowledgeBase extends Model
{
    protected $table = 'knowledge_bases';

    protected $fillable = [
        'project_id', 'name', 'description', 'tags', 'version',
        'clearance', 'embedding_model', 'created_by',
    ];

    protected function casts(): array
    {
        return ['tags' => 'array', 'version' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KbDocument::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
