<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware('web')->group(function (): void {
    Route::get('/health', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);
});
