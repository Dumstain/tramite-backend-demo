<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\BlueprintController;
use App\Http\Controllers\Runner\EngineController;
use App\Http\Controllers\Shared\UploadController;
use App\Http\Controllers\Reviewer\ReviewerController;


// Endpoint de prueba automático de Laravel (puedes borrarlo si quieres)
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// --- NUESTRAS RUTAS ---

// ADMIN (Constructor)
Route::prefix('admin')->group(function () {
    Route::get('/blueprints', [BlueprintController::class, 'index']);
    Route::post('/blueprints', [BlueprintController::class, 'store']);
    Route::get('/blueprints/{id}', [BlueprintController::class, 'show']);
    Route::put('/blueprints/{id}', [BlueprintController::class, 'update']);
});

// RUNNER (Motor)
Route::prefix('engine')->group(function () {
    Route::post('/start', [EngineController::class, 'start']);
    Route::get('/{instance_id}/current', [EngineController::class, 'currentStep']);
    Route::post('/{instance_id}/submit', [EngineController::class, 'submitStep']);
});

// SHARED (Archivos)
Route::post('/uploads/temp', [UploadController::class, 'store']);

// REVIEWER (Revisor)
Route::prefix('reviewer')->group(function () {
    Route::get('/inbox', [ReviewerController::class, 'inbox']);         // Ver lista
    Route::get('/{instance_id}', [ReviewerController::class, 'show']);  // Ver detalle
    Route::post('/{instance_id}/decide', [ReviewerController::class, 'decide']); // Decidir
});

