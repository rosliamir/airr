<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes immutable audit entries (FR-M1.5). Use log() for data events and
 * logAuth() for authentication events.
 */
class AuditService
{
    public function log(
        string $action,
        string $objectType = null,
        $objectId = null,
        array $oldValues = null,
        array $newValues = null,
        int $userId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id'     => $userId ?? Auth::id(),
            'action'      => $action,
            'object_type' => $objectType,
            'object_id'   => $objectId !== null ? (string) $objectId : null,
            'old_values'  => $oldValues,
            'new_values'  => $newValues,
            'ip'          => Request::ip(),
            'user_agent'  => substr((string) Request::userAgent(), 0, 255),
            'created_at'  => now(),
        ]);
    }

    public function logAuth(string $action, int $userId = null): AuditLog
    {
        return $this->log($action, userId: $userId);
    }
}
