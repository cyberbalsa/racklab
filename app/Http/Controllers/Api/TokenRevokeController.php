<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\TrackBTokenService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\TokenGrant;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TokenRevokeController extends Controller
{
    public function __invoke(
        string $tokenGrant,
        Request $request,
        TenantContextStore $tenantContext,
        TrackBTokenService $tokens,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var TokenGrant|null $grant */
        $grant = TokenGrant::query()
            ->whereKey($tokenGrant)
            ->where('owner_user_id', $user->id)
            ->first();

        if (! $grant instanceof TokenGrant) {
            throw new NotFoundHttpException('Token grant not found.');
        }

        $tokens->revoke($user, $context, $grant, $request);

        return response()->noContent();
    }
}
