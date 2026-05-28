<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final readonly class RejectBearerSanctumToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $request->attributes->get(NormalizeTrackBTokenHeader::ATTRIBUTE) === 'bearer'
            && $user instanceof User
            && $user->currentAccessToken() instanceof PersonalAccessToken
        ) {
            throw new AuthenticationException('Track B personal access tokens must use the Token authorization prefix.');
        }

        return $next($request);
    }
}
