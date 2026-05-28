<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MeController extends Controller
{
    public function __invoke(Request $request, TenantContextStore $tenantContext): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($context->activeTenantId);

        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        /** @var UserProfile|null $profile */
        $profile = UserProfile::query()
            ->where('user_id', $user->getKey())
            ->first();

        return response()->json([
            'data' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'tenant' => [
                    'id' => $tenant->getKey(),
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
                'profile' => [
                    'display_name' => $profile?->display_name,
                    'locale' => $profile?->locale,
                ],
            ],
        ]);
    }
}
