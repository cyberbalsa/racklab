<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\NetworkOffering;
use App\Models\Project;
use App\Models\ProviderNetwork;
use App\Models\StackDefinition;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class NetworkAttachmentValidator
{
    /**
     * @var array<string, list<string>>
     */
    private const array SUPPORTED_NETWORK_TYPES = [
        'fake' => ['bridge'],
        'proxmox' => ['bridge', 'vlan', 'vnet', 'sdn_zone'],
    ];

    public function __construct(
        private StackNetworkSpecExtractor $specs,
        private AuditEventWriter $auditEvents,
    ) {}

    public function validateStackForProvider(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $provider,
        Request $request,
        string $effectivePermission,
    ): void {
        foreach ($this->specs->forStack($stack) as $spec) {
            if ($spec->offeringId === null && $spec->offeringSlug === null) {
                $this->deny(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    stack: $stack,
                    spec: $spec,
                    provider: $provider,
                    request: $request,
                    effectivePermission: $effectivePermission,
                    reason: 'missing_network_offering',
                    message: sprintf('Network offering is required for %s:%s.', $spec->componentKey, $spec->key),
                    metadata: [],
                );
            }

            $offering = $this->offering($context->activeTenantId, $spec);

            if (! $offering instanceof NetworkOffering) {
                $this->deny(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    stack: $stack,
                    spec: $spec,
                    provider: $provider,
                    request: $request,
                    effectivePermission: $effectivePermission,
                    reason: 'network_offering_not_found',
                    message: sprintf('Network offering %s is not available.', $this->offeringReference($spec)),
                    metadata: [],
                );
            }

            /** @var ProviderNetwork|null $network */
            $network = $offering->providerNetwork;

            if (! $network instanceof ProviderNetwork) {
                $this->deny(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    stack: $stack,
                    spec: $spec,
                    provider: $provider,
                    request: $request,
                    effectivePermission: $effectivePermission,
                    reason: 'provider_network_not_found',
                    message: sprintf('Network offering %s is missing its provider network.', $this->offeringReference($spec)),
                    metadata: [
                        'network_offering_id' => $offering->getKey(),
                    ],
                );
            }

            if ($network->provider !== $provider) {
                $this->deny(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    stack: $stack,
                    spec: $spec,
                    provider: $provider,
                    request: $request,
                    effectivePermission: $effectivePermission,
                    reason: 'provider_mismatch',
                    message: sprintf(
                        'Network offering %s belongs to provider %s, not %s.',
                        $offering->slug,
                        $network->provider,
                        $provider,
                    ),
                    metadata: [
                        'network_offering_id' => $offering->getKey(),
                        'provider_network_id' => $network->getKey(),
                        'provider_network_provider' => $network->provider,
                    ],
                );
            }

            $supportedTypes = self::SUPPORTED_NETWORK_TYPES[$provider] ?? [];

            if (! in_array($network->network_type, $supportedTypes, true)) {
                $this->deny(
                    actor: $actor,
                    context: $context,
                    project: $project,
                    stack: $stack,
                    spec: $spec,
                    provider: $provider,
                    request: $request,
                    effectivePermission: $effectivePermission,
                    reason: 'unsupported_network_type',
                    message: sprintf(
                        'Network type %s is not supported by provider %s.',
                        $network->network_type,
                        $provider,
                    ),
                    metadata: [
                        'network_offering_id' => $offering->getKey(),
                        'provider_network_id' => $network->getKey(),
                        'network_type' => $network->network_type,
                    ],
                );
            }
        }
    }

    private function offering(string $tenantId, StackNetworkSpec $spec): ?NetworkOffering
    {
        $query = NetworkOffering::query()
            ->where('tenant_id', $tenantId)
            ->with('providerNetwork');

        if ($spec->offeringId !== null) {
            /** @var NetworkOffering|null $offering */
            $offering = $query->whereKey($spec->offeringId)->first();

            return $offering;
        }

        if ($spec->offeringSlug === null) {
            return null;
        }

        /** @var NetworkOffering|null $offering */
        $offering = $query->where('slug', $spec->offeringSlug)->first();

        return $offering;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function deny(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        StackNetworkSpec $spec,
        string $provider,
        Request $request,
        string $effectivePermission,
        string $reason,
        string $message,
        array $metadata,
    ): never {
        $this->auditEvents->append([
            'event_type' => 'network.attach',
            'action' => 'validate',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [$effectivePermission],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'reason' => $reason,
                'provider' => $provider,
                'project_id' => $project->getKey(),
                'stack_definition_id' => $stack->getKey(),
                'network_spec' => $spec->toArray(),
                ...$metadata,
            ],
        ]);

        throw ValidationException::withMessages([
            'network_offering' => [$message],
        ]);
    }

    private function offeringReference(StackNetworkSpec $spec): string
    {
        return ($spec->offeringSlug ?? $spec->offeringId) ?? sprintf('%s:%s', $spec->componentKey, $spec->key);
    }
}
