<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantAccessResource;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreNetworkOfferingRequest;
use App\Models\NetworkOffering;
use App\Models\ProviderNetwork;
use App\Models\User;
use App\Networking\NetworkOfferingPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NetworkOfferingStoreController extends Controller
{
    public function __invoke(
        StoreNetworkOfferingRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $tenantResource = new TenantAccessResource($context->activeTenantId);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('network.attach_provider'),
            $tenantResource,
            $context,
        );

        if (! $tokenAbilities->allows($request, 'network.attach_provider') || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, 'create', 'denied', [
                'slug' => $request->string('slug')->toString(),
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to publish network offerings.');
        }

        $offering = DB::transaction(function () use ($request, $context, $user, $auditEvents): NetworkOffering {
            $providerNetworkInput = $this->stringKeyedArray($request->input('provider_network'));
            $providerNetwork = $this->providerNetwork($context, $providerNetworkInput);

            /** @var NetworkOffering $offering */
            $offering = NetworkOffering::query()->create([
                'tenant_id' => $context->activeTenantId,
                'provider_network_id' => $providerNetwork->getKey(),
                'name' => $request->string('name')->toString(),
                'slug' => $request->string('slug')->toString(),
                'offering_type' => $request->string('offering_type')->toString(),
                'reachability' => $request->string('reachability')->toString(),
                'metadata' => $this->stringKeyedArray($request->input('metadata')),
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ]);

            $this->audit($auditEvents, $user, $context, 'create', 'allowed', [
                'network_offering_id' => $offering->getKey(),
                'provider_network_id' => $providerNetwork->getKey(),
                'slug' => $offering->slug,
                'reachability' => $offering->reachability,
            ]);

            return $offering;
        });

        return response()->json(['data' => NetworkOfferingPayload::make($offering->refresh()->load('providerNetwork'))], 201);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function providerNetwork(TenantContext $context, array $input): ProviderNetwork
    {
        $name = $this->stringValue($input['name'] ?? null, 'Provider network');
        $provider = $this->stringValue($input['provider'] ?? null, 'fake');
        $externalId = $this->stringValue($input['external_id'] ?? null, $name);
        $networkType = $this->stringValue($input['network_type'] ?? null, 'bridge');
        $providerCluster = is_string($input['provider_cluster'] ?? null) && trim($input['provider_cluster']) !== ''
            ? $input['provider_cluster']
            : null;

        /** @var ProviderNetwork $network */
        $network = ProviderNetwork::query()->firstOrCreate(
            [
                'tenant_id' => $context->activeTenantId,
                'provider' => $provider,
                'provider_cluster' => $providerCluster,
                'external_id' => $externalId,
            ],
            [
                'name' => $name,
                'slug' => $this->uniqueProviderNetworkSlug($context->activeTenantId, $name),
                'network_type' => $networkType,
                'bridge' => is_string($input['bridge'] ?? null) ? $input['bridge'] : null,
                'vlan_tag' => is_int($input['vlan_tag'] ?? null) ? $input['vlan_tag'] : null,
                'metadata' => $this->stringKeyedArray($input['metadata'] ?? null),
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ],
        );

        return $network;
    }

    private function uniqueProviderNetworkSlug(string $tenantId, string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'provider-network';
        }

        $candidate = $slug;
        $suffix = 1;

        while (ProviderNetwork::query()->where('tenant_id', $tenantId)->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'network.offering',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'tenant',
            'resource_id' => $context->activeTenantId,
            'resource_tenant' => $context->activeTenantId,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['network.attach_provider'],
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }
}
