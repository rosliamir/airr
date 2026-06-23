<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// FR-M14: a project groups reports and scopes access. Members = direct users
// plus whole user groups.
class Project extends Model
{
    const STATUS_ACTIVE = 'active';
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ARCHIVED = 'archived';

    const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ON_HOLD, self::STATUS_COMPLETED, self::STATUS_ARCHIVED];

    protected $fillable = ['code', 'name', 'customer_name', 'type', 'color', 'status', 'start_date', 'end_date', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'project_user_group');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
