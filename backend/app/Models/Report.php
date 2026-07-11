<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// M6 (FR-M6.1) — report definition. `definition` holds the portable JSON the
// renderer executes and the Studio edits.
class Report extends Model
{
    use SoftDeletes;

    const TYPES = ['table', 'grouped', 'kpi', 'matrix', 'chart', 'document'];

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    const LAYOUTS = ['portrait', 'landscape'];
    const PRINTOUT_SIZES = ['a4', 'a3', 'a2', 'b5', 'custom'];
    const OUTPUT_FORMATS = ['pdf', 'excel', 'csv'];

    protected $fillable = [
        'project_id', 'dataset_id', 'name', 'description', 'type', 'definition', 'status', 'archived_at', 'created_by',
        'version', 'layout', 'printout_size', 'printout_width', 'printout_height', 'output_formats', 'locked', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array', 'archived_at' => 'datetime',
            'output_formats' => 'array', 'locked' => 'boolean', 'tags' => 'array',
        ];
    }

    // Templates (Author > Templates) attached to this report — e.g. one for the
    // report header, another for the page header, etc. Order-agnostic; the
    // renderer/compiler decides how attached templates combine (future M7/M8).
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(Template::class)->withTimestamps();
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
