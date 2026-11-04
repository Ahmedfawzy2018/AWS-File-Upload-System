<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('uploads-initiate', function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof User ? 'user:'.$user->getKey() : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('uploads.rate_limits.initiate', 60))->by($key);
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof User ? 'user:'.$user->getKey() : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('uploads.rate_limits.default', 300))->by($key);
        });
    }
}
