<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/tokens', [TokenController::class, 'store'])->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy']);

        Route::post('uploads', [UploadController::class, 'store'])->middleware(['throttle:uploads-initiate', 'idempotent'])->name('uploads.store');
        Route::post('uploads/{file}/complete', [UploadController::class, 'complete'])->middleware('idempotent')->name('uploads.complete');
        Route::post('uploads/{file}/parts', [UploadController::class, 'parts']);
        Route::post('uploads/{file}/abort', [UploadController::class, 'abort']);

        // Placeholder; replaced in Task 11.
        Route::get('me/usage', fn () => response()->json(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented', 'details' => (object) []]], 501));
    });
});
