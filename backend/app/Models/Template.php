<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'project_id', 'data_source_id', 'dataset_id',
        'header', 'body', 'footer', 'page_header', 'page_footer',
        'groups', 'parameter_screen', 'meta',
        'created_by',
    ];
    // Note: group_header/group_footer columns are deprecated in favour of `groups`
    // (JSON array of {level, header, footer}) — kept in the schema for backward
    // compatibility but no longer written to.

    protected $casts = [
        'groups' => 'array',
        'meta'   => 'array',
    ];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function dataSource(): BelongsTo { return $this->belongsTo(DataSource::class); }
    public function dataset(): BelongsTo { return $this->belongsTo(Dataset::class); }
}
