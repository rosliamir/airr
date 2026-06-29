<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// M4 — a single agent's execution record within an orchestration run.
class OrchestrationStep extends Model
{
    protected $fillable = [
        'orchestration_run_id', 'agent', 'sequence',
        'status', 'input', 'output', 'model_used', 'duration_ms', 'error',
    ];

    protected function casts(): array
    {
        return [
            'input'  => 'array',
            'output' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(OrchestrationRun::class, 'orchestration_run_id');
    }
}
