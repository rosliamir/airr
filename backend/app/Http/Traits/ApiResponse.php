<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Standard AIRR API envelope. Controllers must use this — never raw response()->json().
 *   Success: { "data": ..., "meta": ... }
 *   Error:   { "error": { "code": ..., "message": ..., "details": ... } }
 */
trait ApiResponse
{
    protected function sendOk($data, array $meta = null, int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function sendCreated($data): JsonResponse
    {
        return $this->sendOk($data, null, 201);
    }

    protected function sendNoContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function sendError(int $status, string $code, string $message, $details = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'code'    => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
