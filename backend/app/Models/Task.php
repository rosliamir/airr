<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dashboard "task" widget data source — simple assignable to-dos.
class Task extends Model
{
    const STATUSES = ['open', 'in_progress', 'done'];
    const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'title', 'description', 'assigned_to', 'due_date', 'status', 'priority', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
