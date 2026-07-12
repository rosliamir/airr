<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A viewer's personal, prompt-editable snapshot of a report — see the
// creating migration for why this isn't called "template".
class SavedView extends Model
{
    protected $fillable = ['report_id', 'user_id', 'name', 'definition', 'prompt', 'prompt_history'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'prompt_history' => 'array'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
