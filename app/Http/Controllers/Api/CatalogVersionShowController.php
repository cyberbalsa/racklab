<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Catalog\CatalogPayload;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CatalogVersionShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $catalogItem,
        string $version,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if (! $tokenAbilities->allows($request, 'catalog.read')) {
            throw new AuthorizationException('The current token does not include catalog.read.');
        }

        /** @var CatalogItem|null $item */
        $item = CatalogItem::query()->whereKey($catalogItem)->first();

        if (! $item instanceof CatalogItem) {
            throw new NotFoundHttpException('Catalog item not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('catalog.read'),
            $item,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Catalog item not found.');
        }

        /** @var CatalogVersion|null $model */
        $model = CatalogVersion::query()
            ->with('stackDefinition')
            ->where('catalog_item_id', $item->getKey())
            ->where('state', 'published')
            ->where(function ($query) use ($version): void {
                $query->whereKey($version)->orWhere('version', $version);
            })
            ->first();

        if (! $model instanceof CatalogVersion) {
            throw new NotFoundHttpException('Catalog version not found.');
        }

        return response()->json(['data' => CatalogPayload::version($model)]);
    }
}
