<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateTrackAJwt;
use App\Http\Middleware\BindAuthenticatedTenant;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\NormalizeTrackBTokenHeader;
use App\Http\Middleware\SetTenantContextForOctane;
use App\Jobs\Maintenance\DetectProviderDriftJob;
use App\Jobs\Maintenance\ExpireDeploymentsJob;
use App\Jobs\Maintenance\ReapScriptContainersJob;
use App\Jobs\Maintenance\ReconcileProviderTasksJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', BindAuthenticatedTenant::class]],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            SetTenantContextForOctane::class,
            IdentifyTenant::class,
        ]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, NormalizeTrackBTokenHeader::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AuthenticateTrackAJwt::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Reconciliation/expiry/drift/reaping run as Horizon-dispatched jobs on
        // the `maintenance` queue instead of the legacy `while true` shell loop
        // in the scheduler Quadlet. `withoutOverlapping` stops a slow run from
        // stacking; `onOneServer` keeps a single scheduler authoritative under
        // the Scale profile.
        $reapMaxAge = (int) config('racklab.reaper_max_age_seconds', 3600);

        $schedule->job(new ReconcileProviderTasksJob)->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->job(new ExpireDeploymentsJob)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        $schedule->job(new DetectProviderDriftJob)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->job(new ReapScriptContainersJob($reapMaxAge))->hourly()->withoutOverlapping()->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
