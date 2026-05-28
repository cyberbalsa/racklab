<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AccountLocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $supported = config('racklab.supported_locales', ['en']);
        $supportedLocales = is_array($supported) ? array_values(array_filter($supported, is_string(...))) : ['en'];

        $request->validate([
            'locale' => ['required', 'string', Rule::in($supportedLocales)],
        ]);

        $locale = $request->string('locale')->toString();

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'display_name' => $user->name,
                'locale' => $locale,
            ],
        );

        return redirect()->route('dashboard');
    }
}
