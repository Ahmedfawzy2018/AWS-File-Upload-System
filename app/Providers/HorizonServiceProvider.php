<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * Local: any user. Elsewhere: only emails listed in HORIZON_ALLOWED_EMAILS (comma-separated).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            if (app()->environment('local')) {
                return true;
            }

            $allowed = array_filter(array_map(
                static fn (string $email): string => strtolower(trim($email)),
                explode(',', (string) config('horizon.allowed_emails', '')),
            ));

            return $user !== null && in_array(strtolower((string) $user->email), $allowed, true);
        });
    }
}
