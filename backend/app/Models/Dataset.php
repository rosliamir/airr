<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// M2 Layer 2 — a saved query/call under a data source: the SQL (DB) or
// path/template (API), its output fields, and runtime parameters (FR-M2.5).
class Dataset extends Model
{
    protected $fillable = [
        'data_source_id', 'name', 'description', 'query', 'method', 'body',
        'fields', 'parameters', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fields'     => 'array',
            'parameters' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
