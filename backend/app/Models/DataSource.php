<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// M2 Layer 1 — a connection to a database or API. Credentials are stored
// encrypted at rest (FR-M2.8).
class DataSource extends Model
{
    // NOTE: 'sqlserver' is supported in code (see ConnectorService::DRIVERS) but
    // disabled here until a SQL Server instance + pdo_sqlsrv driver are available.
    const DB_TYPES   = ['postgres', 'mysql', 'oracle'];
    const API_TYPES  = ['rest_api', 'graphql'];
    const FILE_TYPES = ['csv', 'json', 'excel'];
    const TYPES      = ['postgres', 'mysql', 'oracle', 'rest_api', 'graphql', 'csv', 'json', 'excel'];

    protected $fillable = ['project_id', 'name', 'type', 'config', 'schema_cache', 'status', 'created_by'];

    protected function casts(): array
    {
        return [
            'config'       => 'encrypted:array', // encrypted at rest
            'schema_cache' => 'array',
        ];
    }

    public function isDatabase(): bool
    {
        return in_array($this->type, self::DB_TYPES, true);
    }

    public function isFile(): bool
    {
        return in_array($this->type, self::FILE_TYPES, true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }
}
