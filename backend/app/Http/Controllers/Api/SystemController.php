<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    use ApiResponse;

    // Public health + capability probe for the SPA bootstrap.
    public function health(): JsonResponse
    {
        $db = false;
        $pgvector = false;
        $pgai = false;

        try {
            DB::select('select 1');
            $db = true;
            $pgvector = (bool) DB::scalar("select 1 from pg_extension where extname='vector'");
            $pgai = (bool) DB::scalar("select 1 from pg_proc p join pg_namespace n on n.oid=p.pronamespace where n.nspname='ai' limit 1");
        } catch (\Throwable $e) {
            // leave flags false
        }

        return $this->sendOk([
            'status'   => $db ? 'UP' : 'DEGRADED',
            'edition'  => Edition::current(),
            'features' => Edition::enabledFeatures(),
            'services' => [
                'database' => $db,
                'pgvector' => $pgvector,
                'pgai'     => $pgai,
            ],
        ]);
    }
}
