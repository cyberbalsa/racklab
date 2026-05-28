<?php

declare(strict_types=1);

use App\Http\Controllers\AccountLocaleController;
use App\Http\Controllers\AccountTokenRevokeController;
use App\Http\Controllers\AccountTokenStoreController;
use App\Http\Controllers\Api\ArtifactShowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentNewVmController;
use App\Http\Controllers\DeploymentReleaseController;
use App\Http\Controllers\DeploymentShowController;
use App\Http\Controllers\Docs\DocReaderController;
use App\Http\Controllers\Docs\RefResolveController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\JwksController;
use App\Http\Controllers\ScriptFakeRunnerController;
use App\Http\Middleware\BindAuthenticatedTenant;
use App\Http\Middleware\SetUserLocale;
use App\Livewire\Hello;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/hello', Hello::class)->name('hello');

Route::get('/healthz', [HealthController::class, 'liveness'])->name('healthz');
Route::get('/readyz', [HealthController::class, 'readiness'])->name('readyz');

Route::get('/.well-known/jwks.json', JwksController::class)->name('jwks');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('dashboard');

Route::put('/account/locale', AccountLocaleController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('account.locale.update');

Route::post('/account/tokens', AccountTokenStoreController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('account.tokens.store');

Route::post('/account/tokens/{tokenGrant}/revoke', AccountTokenRevokeController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('account.tokens.revoke');

Route::post('/deployments/new-vm', DeploymentNewVmController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('deployments.new-vm.store');

Route::post('/deployments/{deployment}/release', DeploymentReleaseController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('deployments.release');

Route::get('/deployments/{deployment}', DeploymentShowController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('deployments.show');

Route::post('/scripts/fake-runner', ScriptFakeRunnerController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('scripts.fake-runner.store');

Route::get('/artifacts/{artifact}', ArtifactShowController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('artifacts.show');

Route::get('/plugins/docs/refs/resolve/{kind}/{id}', RefResolveController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('plugins.docs.refs.resolve');

Route::get('/docs/{doc}', DocReaderController::class)
    ->middleware(['auth', BindAuthenticatedTenant::class, SetUserLocale::class])
    ->name('docs.show');
