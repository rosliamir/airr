<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// M6 (FR-M6.1) — report definition. `definition` holds the portable JSON the
// renderer executes and the Studio edits.
class Report extends Model
{
    const TYPES = ['table', 'grouped', 'kpi', 'matrix', 'chart', 'document'];

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'project_id', 'dataset_id', 'name', 'description', 'type', 'definition', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    // Many-to-many: projects this report is linked to (FR-M6 — multi-project access).
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_report')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Per-report ACL (FR-M6 ACL): roles granted abilities on this report.
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['can_view', 'can_edit', 'can_run'])
            ->withTimestamps();
    }

    /**
     * Reports a non-admin may see: ones they created, or where a role they hold
     * is granted can_view. (A report with no ACL is private to its creator.)
     */
    public function scopeAccessibleTo($query, User $viewer)
    {
        $roleIds = $viewer->roles()->pluck('roles.id')->all();

        return $query->where(function ($q) use ($viewer, $roleIds) {
            $q->where('created_by', $viewer->id)
                ->orWhereHas('roles', fn ($r) => $r->whereIn('roles.id', $roleIds)->where('report_role.can_view', true));
        });
    }

    /** May $user perform $ability ('view'|'edit'|'run') on this report? */
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
     * Replace the report's ACL. $grants = [['role_id'=>.., 'view'=>bool, 'edit'=>bool, 'run'=>bool], ...]
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
