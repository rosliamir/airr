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

    // Many-to-many: reports linked/accessible to this project (a report may
    // belong to several projects). reports.project_id remains the home project.
    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class, 'project_report')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tiered visibility (FR-M14.3). Caller checks $viewer->seesEverything() first to
     * bypass entirely (super_admin / superadmin role).
     * - system_admin: ONLY projects they created (per RBAC diagram — no membership
     *   fallback for this tier).
     * - user_level_1 / user_level_2 (and anything else): projects they created OR
     *   are a direct member of — the original owner+membership behavior.
     */
    public function scopeVisibleTo($query, User $viewer)
    {
        if ($viewer->user_type === User::TYPE_SYSTEM_ADMIN) {
            return $query->where('created_by', $viewer->id);
        }

        return $query->where(function ($q) use ($viewer) {
            $q->where('created_by', $viewer->id)
                ->orWhereHas('users', fn ($u) => $u->where('users.id', $viewer->id));
        });
    }
}
