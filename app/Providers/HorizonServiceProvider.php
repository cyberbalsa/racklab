<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\HorizonAuthGate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    #[Override]
    public function boot(): void
    {
        parent::boot();

        // Horizon::auth() covers ALL environments (production + local).
        // The parent's authorization() defaults to allowing all local-env
        // access — overriding the callback here makes the gate authoritative
        // regardless of APP_ENV.
        Horizon::auth(function (Request $request): bool {
            /** @var User|null $user */
            $user = $request->user();

            return app(HorizonAuthGate::class)->authorize($user);
        });
    }

    /**
     * Register the Horizon gate (Laravel-side companion to Horizon::auth).
     *
     * Kept for direct Gate::check('viewHorizon') consumers; the request guard
     * itself goes through Horizon::auth above.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => app(HorizonAuthGate::class)->authorize($user));
    }
}
