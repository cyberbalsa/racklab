<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class NormalizeTrackBTokenHeader
{
    public const string ATTRIBUTE = 'racklab.authorization_prefix';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = trim((string) $request->headers->get('Authorization', ''));

        if (str_starts_with(strtolower($authorization), 'token ')) {
            $request->attributes->set(self::ATTRIBUTE, 'token');
            $request->headers->set('Authorization', 'Bearer '.trim(substr($authorization, 6)));
            Auth::guard('web')->forgetUser();
            $this->refreshSanctumRequest($request);
        } elseif (str_starts_with(strtolower($authorization), 'bearer ')) {
            $request->attributes->set(self::ATTRIBUTE, 'bearer');
            Auth::guard('web')->forgetUser();
            $this->refreshSanctumRequest($request);
        }

        return $next($request);
    }

    private function refreshSanctumRequest(Request $request): void
    {
        $guard = Auth::guard('sanctum');

        if ($guard instanceof RequestGuard) {
            $guard->setRequest($request);
            $guard->forgetUser();
        }
    }
}
