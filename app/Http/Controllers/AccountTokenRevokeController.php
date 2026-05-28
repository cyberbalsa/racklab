<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Tokens\TrackBTokenService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\TokenGrant;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountTokenRevokeController extends Controller
{
    public function __invoke(
        Request $request,
        string $tokenGrant,
        TenantContextStore $tenantContext,
        TrackBTokenService $tokens,
    ): RedirectResponse {
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

        return redirect()
            ->route('dashboard')
            ->with('status', __('racklab.dashboard.token_revoked'));
    }
}
