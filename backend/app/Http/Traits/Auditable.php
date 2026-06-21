<?php

namespace App\Http\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Attach to data models to auto-log create/update/delete to the audit trail.
 * Sensitive keys are filtered out of recorded values.
 */
trait Auditable
{
    protected static array $auditExclude = ['password', 'remember_token'];

    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAudit('create', null, $model->auditAttributes()));
        static::updated(fn ($model) => $model->writeAudit(
            'update',
            $model->auditAttributes(array_keys($model->getChanges()), 'original'),
            $model->auditAttributes(array_keys($model->getChanges())),
        ));
        static::deleted(fn ($model) => $model->writeAudit('delete', $model->auditAttributes(), null));
    }

    protected function auditAttributes(array $only = null, string $source = 'attributes'): array
    {
        $data = $source === 'original' ? $this->getOriginal() : $this->getAttributes();
        if ($only !== null) {
            $data = array_intersect_key($data, array_flip($only));
        }

        return collect($data)->except(static::$auditExclude)->all();
    }

    protected function writeAudit(string $verb, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => class_basename(static::class) . '.' . $verb,
            'object_type' => static::class,
            'object_id'   => (string) $this->getKey(),
            'old_values'  => $old,
            'new_values'  => $new,
            'ip'          => Request::ip(),
            'user_agent'  => substr((string) Request::userAgent(), 0, 255),
            'created_at'  => now(),
        ]);
    }
}
