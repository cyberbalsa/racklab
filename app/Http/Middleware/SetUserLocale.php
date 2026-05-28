<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetUserLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            /** @var UserProfile|null $profile */
            $profile = UserProfile::query()
                ->where('user_id', $user->id)
                ->first();

            if ($profile instanceof UserProfile && $this->isSupported($profile->locale)) {
                App::setLocale($profile->locale);
            }
        }

        return $next($request);
    }

    private function isSupported(string $locale): bool
    {
        $supported = config('racklab.supported_locales', ['en']);

        return is_array($supported) && in_array($locale, $supported, strict: true);
    }
}
