<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\UpdateUserPassword;
use App\Listeners\AuditAuthEvent;
use App\Listeners\EnsurePersonalProjectForLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::loginView(static fn (): Factory|View => view('auth.login'));
        Fortify::registerView(static fn (): Factory|View => view('auth.register'));

        Event::listen(Login::class, EnsurePersonalProjectForLogin::class);
        Event::listen(Registered::class, AuditAuthEvent::class);
        Event::listen(Login::class, AuditAuthEvent::class);
        Event::listen(Failed::class, AuditAuthEvent::class);
        Event::listen(Logout::class, AuditAuthEvent::class);
        Event::listen(PasswordUpdatedViaController::class, AuditAuthEvent::class);
    }
}
