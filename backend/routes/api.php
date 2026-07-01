<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\ConstantController;
use App\Http\Controllers\Api\OrchestrationController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\FeatureController;
use App\Http\Controllers\Api\KbDocumentController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
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
    Route::put('me/current-project', [AuthController::class, 'setCurrentProject']);

    // M1.5 — audit trail (read gated)
    Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.read');

    // M14 — user administration (gated by users.manage)
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
    });

    // M14 — DB-driven navigation. nav() for any user; CRUD gated by menus.manage.
    Route::get('menus/nav', [MenuController::class, 'nav']);
    Route::middleware('permission:menus.manage')->group(function () {
        Route::get('menus', [MenuController::class, 'index']);
        Route::post('menus', [MenuController::class, 'store']);
        Route::put('menus/{menu}', [MenuController::class, 'update']);
        Route::delete('menus/{menu}', [MenuController::class, 'destroy']);
    });

    // Role picker options (any authenticated user — e.g. per-report ACL UI)
    Route::get('roles/options', [RoleController::class, 'options']);

    // M14 — role administration (gated by roles.manage)
    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });

    // M14 — lookups: read active values (any user, for dropdowns) + CRUD (admin)
    Route::get('lookups', [LookupController::class, 'index']);
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('lookups/manage', [LookupController::class, 'manage']);
        Route::post('lookups', [LookupController::class, 'store']);
        Route::put('lookups/{lookup}', [LookupController::class, 'update']);
        Route::delete('lookups/{lookup}', [LookupController::class, 'destroy']);
    });

    // M14 — global settings
    Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:settings.manage');
    Route::put('settings/regional', [SettingsController::class, 'updateRegional'])->middleware('permission:settings.manage');

    // M14.6 / M15.6-7 — AI provider & model administration
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('ai/config', [AiController::class, 'index']);
        Route::put('ai/config', [AiController::class, 'updateDefaults']);
        Route::get('ai/health', [AiController::class, 'health']);
    });

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
        Route::post('data-sources/{id}/restore', [DataSourceController::class, 'restore']);
        Route::post('data-sources/{dataSource}/test', [DataSourceController::class, 'test']);
        Route::post('data-sources/{dataSource}/introspect', [DataSourceController::class, 'introspect']);
        Route::post('data-sources/{dataSource}/upload', [DataSourceController::class, 'uploadFile']);
        Route::get('data-sources/{dataSource}/history', [DataSourceController::class, 'history']);
        Route::get('data-sources/{dataSource}/logs', [DataSourceController::class, 'logs']);

        Route::post('data-sources/{dataSource}/datasets', [DatasetController::class, 'store']);
        Route::put('datasets/{dataset}', [DatasetController::class, 'update']);
        Route::delete('datasets/{dataset}', [DatasetController::class, 'destroy']);
        Route::post('datasets/{dataset}/preview', [DatasetController::class, 'preview']);
        Route::get('datasets/{dataset}/history', [DatasetController::class, 'history']);
        Route::get('datasets/{dataset}/logs', [DatasetController::class, 'logs']);
    });

    // M3 — Knowledge Base (RAG) + documents (ingestion)
    Route::middleware('permission:kb.view')->group(function () {
        Route::get('knowledge-bases/categories', [KnowledgeBaseController::class, 'categories']);
        Route::get('knowledge-bases', [KnowledgeBaseController::class, 'index']);
        Route::get('knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'show']);
        Route::get('knowledge-bases/{knowledgeBase}/documents', [KbDocumentController::class, 'index']);
        Route::get('kb-documents/{kbDocument}/versions', [KbDocumentController::class, 'versions']);
    });
    Route::middleware('permission:kb.manage')->group(function () {
        Route::post('knowledge-bases', [KnowledgeBaseController::class, 'store']);
        Route::put('knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'update']);
        Route::delete('knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy']);
        Route::post('knowledge-bases/{knowledgeBase}/documents', [KbDocumentController::class, 'store']);
        Route::post('knowledge-bases/{knowledgeBase}/system', [KbDocumentController::class, 'ingestSystem']);
        Route::post('kb-documents/{kbDocument}/versions', [KbDocumentController::class, 'addVersion']);
        Route::post('kb-documents/{kbDocument}/reindex', [KbDocumentController::class, 'reindex']);
        Route::delete('kb-documents/{kbDocument}', [KbDocumentController::class, 'destroy']);
    });

    // Module readiness board (dashboard drill-down)
    Route::get('modules/features/summary', [FeatureController::class, 'summary']);
    Route::get('modules/{code}/features', [FeatureController::class, 'module']);
    Route::put('features/{key}', [FeatureController::class, 'update'])->middleware('permission:settings.manage');

    // M6 — Report definitions + render engine
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/{report}', [ReportController::class, 'show']);
    });
    Route::post('reports/{report}/run', [ReportController::class, 'run'])->middleware('permission:reports.run');
    Route::middleware('permission:reports.create')->group(function () {
        Route::post('reports', [ReportController::class, 'store']);
    });
    Route::middleware('permission:reports.edit')->group(function () {
        Route::put('reports/{report}', [ReportController::class, 'update']);
        Route::delete('reports/{report}', [ReportController::class, 'destroy']);
    });
    Route::get('reports/{report}/history', [ReportController::class, 'history'])->middleware('permission:reports.view');
    Route::get('reports/{report}/logs', [ReportController::class, 'logs'])->middleware('permission:reports.view');

    // Templates (global + project-scoped)
    Route::get('templates', [TemplateController::class, 'index']);
    Route::get('templates/{template}', [TemplateController::class, 'show']);
    Route::middleware('permission:reports.create')->group(function () {
        Route::post('templates', [TemplateController::class, 'store']);
        Route::put('templates/{template}', [TemplateController::class, 'update']);
        Route::delete('templates/{template}', [TemplateController::class, 'destroy']);
    });

    // Constants (system/global/project)
    Route::get('constants', [ConstantController::class, 'index']);
    Route::middleware('permission:settings.manage')->group(function () {
        Route::post('constants', [ConstantController::class, 'store']);
        Route::put('constants/{constant}', [ConstantController::class, 'update']);
        Route::delete('constants/{constant}', [ConstantController::class, 'destroy']);
    });

    // M4 — AI Orchestration (multi-agent pipeline)
    Route::prefix('orchestration')->group(function () {
        Route::post('/', [OrchestrationController::class, 'store'])->middleware('permission:reports.create');
        Route::get('/', [OrchestrationController::class, 'index'])->middleware('permission:reports.view');
        Route::get('{run}', [OrchestrationController::class, 'show'])->middleware('permission:reports.view');
        Route::post('{run}/compliance', [OrchestrationController::class, 'checkCompliance'])->middleware('permission:reports.edit');
        Route::post('{run}/promote', [OrchestrationController::class, 'promote'])->middleware('permission:reports.create');
    });

    // M14 — projects (reports are stored by project)
    Route::get('projects', [ProjectController::class, 'index'])->middleware('permission:projects.view');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view');
    Route::middleware('permission:projects.manage')->group(function () {
        Route::post('projects', [ProjectController::class, 'store']);
        Route::put('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
        // Quick link management from the project card
        Route::get('projects/{project}/report-links', [ProjectController::class, 'reportLinks']);
        Route::put('projects/{project}/reports', [ProjectController::class, 'syncReports']);
        Route::get('projects/{project}/user-links', [ProjectController::class, 'userLinks']);
        Route::put('projects/{project}/users', [ProjectController::class, 'syncUsers']);
    });
});
