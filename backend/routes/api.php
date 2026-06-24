<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserGroupController;
use Illuminate\Support\Facades\Route;

// All AIRR API routes live here (single route-file policy).

// --- Public ---
Route::get('health', [SystemController::class, 'health']);

// --- Auth ---
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    // Google OAuth (browser redirect flow — not XHR).
    Route::get('google/redirect', [AuthController::class, 'redirectGoogle']);
    Route::get('google/callback', [AuthController::class, 'handleGoogleCallback']);
});

// --- Protected (Sanctum) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // M1.5 — audit trail (read gated)
    Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.read');

    // M14 — user administration + groups (gated by users.manage)
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::post('users/{user}/approve', [UserController::class, 'approve']);
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles']);
        Route::post('users/{user}/suspend', [UserController::class, 'suspend']);
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
        Route::post('users/{user}/avatar', [UserController::class, 'uploadAvatar']);

        // User groups CRUD
        Route::get('user-groups', [UserGroupController::class, 'index']);
        Route::post('user-groups', [UserGroupController::class, 'store']);
        Route::put('user-groups/{userGroup}', [UserGroupController::class, 'update']);
        Route::delete('user-groups/{userGroup}', [UserGroupController::class, 'destroy']);
    });

    // M14 — role administration (gated by roles.manage)
    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });

    // M14 — global settings
    Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:settings.manage');
    Route::put('settings/regional', [SettingsController::class, 'updateRegional'])->middleware('permission:settings.manage');

    // M2 — Data Sources (connections) + Datasets (queries/params)
    Route::middleware('permission:datasources.view')->group(function () {
        Route::get('data-sources', [DataSourceController::class, 'index']);
        Route::get('data-sources/{dataSource}', [DataSourceController::class, 'show']);
        Route::get('data-sources/{dataSource}/datasets', [DatasetController::class, 'index']);
    });
    Route::middleware('permission:datasources.manage')->group(function () {
        Route::post('data-sources', [DataSourceController::class, 'store']);
        Route::put('data-sources/{dataSource}', [DataSourceController::class, 'update']);
        Route::delete('data-sources/{dataSource}', [DataSourceController::class, 'destroy']);
        Route::post('data-sources/{dataSource}/test', [DataSourceController::class, 'test']);
        Route::post('data-sources/{dataSource}/introspect', [DataSourceController::class, 'introspect']);
        Route::post('data-sources/{dataSource}/upload', [DataSourceController::class, 'uploadFile']);

        Route::post('data-sources/{dataSource}/datasets', [DatasetController::class, 'store']);
        Route::put('datasets/{dataset}', [DatasetController::class, 'update']);
        Route::delete('datasets/{dataset}', [DatasetController::class, 'destroy']);
        Route::post('datasets/{dataset}/preview', [DatasetController::class, 'preview']);
    });

    // M14 — projects (reports are stored by project)
    Route::get('projects', [ProjectController::class, 'index'])->middleware('permission:projects.view');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view');
    Route::middleware('permission:projects.manage')->group(function () {
        Route::post('projects', [ProjectController::class, 'store']);
        Route::put('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    });
});
