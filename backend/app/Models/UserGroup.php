<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// FR-M14.4: organisational grouping for users (e.g. department/team).
// Distinct from roles — groups scope *who*, roles scope *what they can do*.
class UserGroup extends Model
{
    protected $fillable = ['name', 'description'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
