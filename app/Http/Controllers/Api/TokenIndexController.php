<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\TokenGrant;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TokenIndexController extends Controller
{
    public function __invoke(Request $request, TenantContextStore $tenantContext): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $tenantContext->current() instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        return response()->json([
            'data' => TokenGrant::query()
                ->where('owner_user_id', $user->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (TokenGrant $grant): array => self::serializeGrant($grant, includeSecret: false))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeGrant(TokenGrant $grant, bool $includeSecret): array
    {
        $data = [
            'id' => $grant->getKey(),
            'name' => $grant->name,
            'track' => $grant->track,
            'tenant_id' => $grant->tenant_id,
            'resource_type' => $grant->resource_type,
            'resource_id' => $grant->resource_id,
            'abilities' => $grant->abilities,
            'expires_at' => $grant->expires_at?->toJSON(),
            'last_used_at' => $grant->last_used_at?->toJSON(),
            'revoked_at' => $grant->revoked_at?->toJSON(),
        ];

        if ($includeSecret) {
            $data['plain_text_token'] = null;
            $data['authorization_header'] = null;
        }

        return $data;
    }
}
