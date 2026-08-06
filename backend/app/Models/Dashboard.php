<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Dashboard Authoring — a dashboard is a grid of widgets (report/text/task/
// announcement). `definition` holds the portable JSON the renderer executes
// and the Studio edits. ACL shape mirrors Report exactly.
class Dashboard extends Model
{
    use SoftDeletes;

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name', 'description', 'definition', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Per-dashboard ACL: roles granted abilities on this dashboard.
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'dashboard_role')
            ->withPivot(['can_view', 'can_edit', 'can_run'])
            ->withTimestamps();
    }

    /**
     * Dashboards a non-admin may see: ones they created, or where a role they
     * hold is granted can_view. (A dashboard with no ACL is private to its creator.)
     */
    public function scopeAccessibleTo($query, User $viewer)
    {
        $roleIds = $viewer->roles()->pluck('roles.id')->all();

        return $query->where(function ($q) use ($viewer, $roleIds) {
            $q->where('created_by', $viewer->id)
                ->orWhereHas('roles', fn ($r) => $r->whereIn('roles.id', $roleIds)->where('dashboard_role.can_view', true));
        });
    }

    /** May $user perform $ability ('view'|'edit'|'run') on this dashboard? */
    public function allows(User $user, string $ability): bool
    {
        if ($user->seesEverything() || $this->created_by === $user->id) {
            return true;
        }
        $roleIds = $user->roles()->pluck('roles.id')->all();

        return $this->roles()
            ->whereIn('roles.id', $roleIds)
            ->wherePivot("can_{$ability}", true)
            ->exists();
    }

    /**
     * Replace the dashboard's ACL. $grants = [['role_id'=>.., 'view'=>bool, 'edit'=>bool, 'run'=>bool], ...]
     */
    public function syncPermissions(array $grants): void
    {
        $this->roles()->sync(collect($grants)->mapWithKeys(fn ($g) => [
            $g['role_id'] => [
                'can_view' => (bool) ($g['view'] ?? true),
                'can_edit' => (bool) ($g['edit'] ?? false),
                'can_run'  => (bool) ($g['run'] ?? true),
            ],
        ])->all());
    }
}
