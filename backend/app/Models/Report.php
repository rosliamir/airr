<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// M6 (FR-M6.1) — report definition. `definition` holds the portable JSON the
// renderer executes and the Studio edits.
class Report extends Model
{
    const TYPES = ['table', 'grouped', 'kpi', 'matrix', 'chart', 'document'];

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'project_id', 'dataset_id', 'name', 'description', 'type', 'definition', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Owner+project visibility (FR-M14.3), mirrors data sources / KB.
    public function scopeVisibleTo($query, User $viewer)
    {
        return $query->where(function ($q) use ($viewer) {
            $q->where('created_by', $viewer->id)
                ->orWhereHas('project', fn ($p) => $p->visibleTo($viewer));
        });
    }
}
