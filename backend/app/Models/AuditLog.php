<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    // Append-only: no updated_at, never mutated after creation (FR-M1.5).
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'object_type', 'object_id',
        'old_values', 'new_values', 'ip', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
