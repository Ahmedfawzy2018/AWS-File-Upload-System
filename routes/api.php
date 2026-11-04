<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UsageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/tokens', [TokenController::class, 'store'])->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy']);

        Route::post('uploads', [UploadController::class, 'store'])->middleware(['throttle:uploads-initiate', 'idempotent'])->name('uploads.store');
        Route::post('uploads/{file}/complete', [UploadController::class, 'complete'])->middleware('idempotent')->name('uploads.complete');
        Route::post('uploads/{file}/parts', [UploadController::class, 'parts']);
        Route::post('uploads/{file}/abort', [UploadController::class, 'abort']);

        Route::get('files', [FileController::class, 'index']);
        Route::get('files/{file}', [FileController::class, 'show']);
        Route::get('files/{file}/download', [FileController::class, 'download']);
        Route::delete('files/{file}', [FileController::class, 'destroy']);
        Route::get('me/usage', [UsageController::class, 'show']);
    });
});
