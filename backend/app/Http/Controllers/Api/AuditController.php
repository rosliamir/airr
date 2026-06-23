<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    use ApiResponse;

    // FR-M1.5: read/export the audit trail (gated by audit.read).
    public function index(Request $request): JsonResponse
    {
        $page  = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 10);
        $q     = trim((string) $request->input('q'));

        $query = AuditLog::query()->with('user:id,name,email');

        // Search across action, object type and the actor's name/email.
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('action', 'like', "%{$q}%")
                    ->orWhere('object_type', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
            });
        }

        $total = $query->count();
        $rows = $query->orderByDesc('created_at')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return $this->sendOk($rows, [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'totalPages' => (int) ceil($total / max($limit, 1)),
        ]);
    }
}
