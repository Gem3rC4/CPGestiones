<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(10)->by('login:'.$r->ip()), Limit::perMinute(5)->by('email:'.hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('cliente-login', fn (Request $r) => Limit::perMinute(10)->by('cliente-login:'.hash('sha256', mb_strtolower(trim((string) $r->input('correo_electronico'))))));
        RateLimiter::for('api', function (Request $r) {
            $actor = (string) $r->attributes->get('actor', $r->ip());
            if (! str_starts_with($actor, 'service:')) {
                return Limit::perMinute(180)->by($actor);
            }
            // Integration servers carry every end customer: budget per customer plus a global ceiling.
            $customer = $r->input('cliente_ref', $r->header('X-Cliente-Token', ''));

            return [Limit::perMinute(1800)->by($actor), Limit::perMinute(180)->by($actor.':'.hash('sha256', (string) (is_string($customer) ? $customer : '')))];
        });
    }
}
