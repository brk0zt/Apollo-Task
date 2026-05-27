<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Middleware\AuthRateLimiter;
use App\Http\Middleware\ApiRateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/run-migrations', function () {
    try {
        Artisan::call('migrate', ['--force' => true]);
        return "Tablolar başarıyla kuruldu! Çıktı: " . Artisan::output();
    } catch (\Exception $e) {
        return "Bir hata oluştu: " . $e->getMessage();
    }
});

// ==========================================
// AUTHENTICATION ROUTES (Aggressive Rate Limiting)
// ==========================================
Route::middleware([AuthRateLimiter::class])->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

// ==========================================
// PROTECTED API ROUTES (Sanctum + UX-Preserving API Rate Limiting)
// ==========================================
Route::middleware(['auth:sanctum', ApiRateLimiter::class])->group(function () {
    // Session state
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Projects CRUD
    Route::apiResource('projects', ProjectController::class);
    
    // Tasks CRUD
    Route::apiResource('projects.tasks', TaskController::class);
    Route::patch('/tasks/{id}/complete', [TaskController::class, 'complete']);
    
    // Analytics
    Route::get('/analytics/forecast/{project_id}', [AnalyticsController::class, 'forecast']);
    Route::get('/analytics/risk/{project_id}', [AnalyticsController::class, 'risk']);
    Route::get('/analytics/patterns', [AnalyticsController::class, 'patterns']);
    Route::get('/analytics/timeseries', [AnalyticsController::class, 'timeseries']);
    Route::get('/clear-cache', function() {
    Artisan::call('optimize:clear');
    return "Cache cleared successfully";
});
});