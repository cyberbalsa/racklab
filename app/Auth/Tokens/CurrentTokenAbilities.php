<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use App\Auth\Jwt\TrackAJwtClaims;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasAbilities;

final readonly class CurrentTokenAbilities
{
    public function allows(Request $request, string $ability): bool
    {
        $claims = $request->attributes->get(TrackAJwtClaims::REQUEST_ATTRIBUTE);

        if ($claims instanceof TrackAJwtClaims) {
            return in_array($ability, $claims->permissions, strict: true);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof HasAbilities) {
            return true;
        }

        return ! $token->cant($ability);
    }
}
