<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Constant extends Model
{
    const SCOPE_SYSTEM  = 'system';
    const SCOPE_GLOBAL  = 'global';
    const SCOPE_PROJECT = 'project';

    protected $fillable = ['scope', 'project_id', 'key', 'label', 'value', 'format', 'sort', 'created_by'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
}
