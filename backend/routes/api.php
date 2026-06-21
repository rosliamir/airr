<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

// All AIRR API routes live here (single route-file policy).

// --- Public ---
Route::get('health', [SystemController::class, 'health']);

// --- Auth ---
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

// --- Protected (Sanctum) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // M1.5 — audit trail (read gated)
    Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.read');
});
