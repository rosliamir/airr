<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// RBAC gate (FR-M1.2). Usage: ->middleware('permission:reports.view')
class CheckPermission
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->sendError(401, 'UNAUTHORIZED', 'Not authenticated');
        }

        if (! $user->hasPermission($permission)) {
            return $this->sendError(403, 'FORBIDDEN', "Missing permission: {$permission}");
        }

        return $next($request);
    }
}
