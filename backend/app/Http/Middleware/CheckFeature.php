<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use App\Support\Edition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Edition gate (open-core). Usage: ->middleware('feature:multi_agent')
class CheckFeature
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! Edition::allows($feature)) {
            return $this->sendError(
                403,
                'FEATURE_LOCKED',
                "Feature '{$feature}' is not available in the '" . Edition::current() . "' edition.",
                ['edition' => Edition::current()],
            );
        }

        return $next($request);
    }
}
