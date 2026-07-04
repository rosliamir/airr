<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Constant extends Model
{
    const SCOPE_SYSTEM  = 'system';
    const SCOPE_GLOBAL  = 'global';
    const SCOPE_PROJECT = 'project';

    const TYPE_TEXT  = 'text';
    const TYPE_DATA  = 'data';
    const TYPE_IMAGE = 'image';
    const TYPE_CALC  = 'calc';

    protected $fillable = [
        'scope', 'type', 'project_id', 'key', 'label', 'value', 'format', 'sort',
        'data_source_id', 'dataset_id', 'data_column', 'image_path', 'formula', 'created_by',
    ];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function dataSource(): BelongsTo { return $this->belongsTo(DataSource::class); }
    public function dataset(): BelongsTo { return $this->belongsTo(Dataset::class); }
}
