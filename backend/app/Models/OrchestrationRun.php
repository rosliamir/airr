<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// M4 (FR-M4.1) — a single pipeline execution for a user prompt.
class OrchestrationRun extends Model
{
    use HasFactory;
    const STATUS_QUEUED   = 'queued';
    const STATUS_RUNNING  = 'running';
    const STATUS_AUDITING = 'auditing';
    const STATUS_COMPLETE = 'complete';
    const STATUS_FAILED   = 'failed';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'project_id', 'report_id', 'created_by', 'data_source_id',
        'prompt', 'intent', 'context_fusion',
        'status', 'output_html', 'executive_summary',
        'compliance_notes', 'compliance_passed',
        'error', 'total_duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'intent'           => 'array',
            'context_fusion'   => 'array',
            'compliance_passed'=> 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OrchestrationStep::class)->orderBy('sequence');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETE,
            self::STATUS_FAILED,
            self::STATUS_REJECTED,
        ]);
    }
}
