<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(30)->by($request->ip()),
                Limit::perMinute(5)->by(strtolower($request->input('email'))),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('verification-notification', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(10)->by('ip:' . $request->ip()),
                Limit::perMinute(2)->by('email-minute:' . $email),
                Limit::perHour(5)->by('email-hour:' . $email),
            ];
        });

        RateLimiter::for('verify-email', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
