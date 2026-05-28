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

final class CatalogItemIndexController extends Controller
{
    public function __invoke(
        Request $request,
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

        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('catalog.read');
        $items = [];

        /** @var CatalogItem $item */
        foreach (CatalogItem::query()->orderBy('name')->orderBy('id')->get() as $item) {
            $decision = $accessResolver->permitted($actor, $permission, $item, $context);
            $currentVersion = $this->currentVersion($item);

            if ($decision->allowed && $currentVersion instanceof CatalogVersion) {
                $items[] = CatalogPayload::item($item, $currentVersion);
            }
        }

        return response()->json(['data' => $items]);
    }

    private function currentVersion(CatalogItem $item): ?CatalogVersion
    {
        /** @var CatalogVersion|null $version */
        $version = CatalogVersion::query()
            ->where('catalog_item_id', $item->getKey())
            ->where('state', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        return $version;
    }
}
