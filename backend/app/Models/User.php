<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'status', 'user_type', 'current_project_id', 'created_by', 'auth_provider', 'google_id', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';

    // Account classification (NOT an access grant — access comes from roles).
    // Project-visibility tier: super_admin sees all; system_admin sees only projects
    // they created; user_level_1/2 see projects they're a member of (or created).
    const TYPE_SUPER_ADMIN = 'super_admin';
    const TYPE_SYSTEM_ADMIN = 'system_admin';
    const TYPE_USER_LEVEL_1 = 'user_level_1';
    const TYPE_USER_LEVEL_2 = 'user_level_2';

    const USER_TYPES = [self::TYPE_SUPER_ADMIN, self::TYPE_SYSTEM_ADMIN, self::TYPE_USER_LEVEL_1, self::TYPE_USER_LEVEL_2];

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Can this account see every user/project (bypass owner+group scoping)?
     * Only the super_admin user_type, or the superadmin role. system_admin is
     * NOT included — it is scoped to its own created projects (Project::scopeVisibleTo()).
     */
    public function seesEverything(): bool
    {
        return $this->user_type === self::TYPE_SUPER_ADMIN
            || $this->roles()->where('slug', 'superadmin')->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    /** Projects this user may work in (admins: all; else owned/member). */
    public function accessibleProjects()
    {
        $q = Project::query()->orderBy('name');

        return $this->seesEverything() ? $q : $q->visibleTo($this);
    }

    /** Ensure a current project is set on login; default to the first accessible. */
    public function ensureCurrentProject(): void
    {
        if ($this->current_project_id && $this->accessibleProjects()->whereKey($this->current_project_id)->exists()) {
            return;
        }
        $first = $this->accessibleProjects()->first();
        if ($first && $first->id !== $this->current_project_id) {
            $this->forceFill(['current_project_id' => $first->id])->save();
        }
    }

    /**
     * Owner visibility scope (FR-M14.3): limit a user query to accounts the
     * viewer created or the viewer themselves. Exempt accounts are not scoped
     * (caller checks seesEverything() first).
     */
    public function scopeVisibleTo($query, User $viewer)
    {
        return $query->where(function ($q) use ($viewer) {
            $q->where('created_by', $viewer->id)->orWhere('id', $viewer->id);
        });
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** Does the user hold the given permission key through any role? (FR-M1.2) */
    public function hasPermission(string $key): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('key', $key))
            ->exists();
    }

    /** Highest clearance across the user's roles — used by zero-trust RAG / RLS. */
    public function clearance(): int
    {
        return (int) $this->roles()->max('clearance');
    }

    /** Flattened list of permission keys (for the SPA bootstrap / token abilities). */
    public function permissionKeys(): array
    {
        return $this->roles()
            ->with('permissions:id,key')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }
}
