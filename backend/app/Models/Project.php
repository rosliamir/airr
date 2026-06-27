<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// FR-M14: a project groups reports and scopes access. Members = direct users.
class Project extends Model
{
    const STATUS_ACTIVE = 'active';
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ARCHIVED = 'archived';

    const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ON_HOLD, self::STATUS_COMPLETED, self::STATUS_ARCHIVED];

    protected $fillable = ['code', 'name', 'customer_name', 'type', 'color', 'status', 'start_date', 'end_date', 'description', 'ai_config', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'ai_config' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Owner+membership visibility (FR-M14.3): projects the viewer created or is a
     * direct member of. Caller checks $viewer->seesEverything() first to bypass.
     */
    public function scopeVisibleTo($query, User $viewer)
    {
        return $query->where(function ($q) use ($viewer) {
            $q->where('created_by', $viewer->id)
                ->orWhereHas('users', fn ($u) => $u->where('users.id', $viewer->id));
        });
    }
}
