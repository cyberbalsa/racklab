<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\User;
use App\Networking\NetworkAttachmentValidator;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\ProxmoxProviderOperations;
use App\Quota\LeasePolicyService;
use App\Quota\QuotaReservationService;
use App\Scheduling\PlacementDecision;
use App\Scheduling\PlacementRequest;
use App\Scheduling\ProviderScheduler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class ProxmoxDeploymentLifecycle
{
    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
        private ProxmoxProviderOperations $proxmox,
        private QuotaReservationService $quota,
        private LeasePolicyService $leasePolicies,
        private ProviderScheduler $scheduler,
        private NetworkAttachmentValidator $networkValidator,
    ) {}

    public function request(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $operationKind,
        string $idempotencyKey,
        Request $request,
    ): DeploymentCreateResult {
        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $actor->id),
            new Permission('deployment.create'),
            $project,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to create deployments for this project.');
        }

        /** @var DeploymentOperation|null $existingOperation */
        $existingOperation = DeploymentOperation::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingOperation instanceof DeploymentOperation) {
            /** @var Deployment $deployment */
            $deployment = Deployment::query()->whereKey($existingOperation->deployment_id)->firstOrFail();

            return new DeploymentCreateResult($deployment, $existingOperation, idempotentReplay: true);
        }

        $this->networkValidator->validateStackForProvider(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            provider: 'proxmox',
            request: $request,
            effectivePermission: 'deployment.create',
        );

        $component = $this->firstProxmoxComponent($stack);
        $placement = $this->resolvePlacement($context, $component);
        if ($placement instanceof PlacementDecision) {
            $component['proxmox']['node'] = $placement->node;
        }

        $operationKind = $operationKind === 'add_vm' ? 'add_vm' : 'deploy';
        $lease = $this->leasePolicies->forDeploymentCreate(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            operationKind: $operationKind,
            newDeployment: true,
            requestedDurationMinutes: $this->leaseDurationMinutes($request),
        );
        $reservations = $this->quota->reserveDeploymentCreate(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            operationKind: $operationKind,
            newDeployment: true,
            extraRequirements: $lease->quotaRequirements,
        );

        try {
            $result = DB::transaction(function () use ($actor, $context, $project, $stack, $operationKind, $idempotencyKey, $request, $component, $reservations, $placement, $lease): array {
                /** @var Deployment $deployment */
                $deployment = Deployment::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'project_id' => $project->getKey(),
                    'stack_definition_id' => $stack->getKey(),
                    'requested_by_id' => $actor->getKey(),
                    'name' => $stack->name,
                    'state' => 'pending',
                    'provider' => 'proxmox',
                    'lease_expires_at' => $lease->expiresAt,
                    'metadata' => [
                        'source' => 'proxmox_provider',
                        ...($lease->metadata === [] ? [] : ['lease' => $lease->metadata]),
                    ],
                    'sharing_scope' => 'tenant_local',
                    'shared_with_tenants' => [],
                ]);

                RoleBinding::query()->create([
                    'principal_type' => 'user',
                    'principal_id' => (string) $actor->id,
                    'role' => 'student',
                    'resource_type' => $deployment->resourceType(),
                    'resource_id' => $deployment->resourceId(),
                    'scope_type' => RoleBindingScopeType::TenantLocal,
                    'tenant_id' => $context->activeTenantId,
                    'tenant_set' => [$context->activeTenantId],
                    'granted_by_id' => $actor->getKey(),
                    'granted_reason' => 'deployment requester access',
                ]);

                /** @var DeploymentResource $resource */
                $resource = DeploymentResource::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'deployment_id' => $deployment->getKey(),
                    'component_key' => $component['key'],
                    'kind' => $component['kind'],
                    'state' => 'pending',
                    'provider' => 'proxmox',
                    'provider_resource_id' => null,
                    'metadata' => [
                        'proxmox' => $component['proxmox'],
                    ],
                ]);

                /** @var DeploymentOperation $operation */
                $operation = DeploymentOperation::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'deployment_id' => $deployment->getKey(),
                    'project_id' => $project->getKey(),
                    'stack_definition_id' => $stack->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'kind' => $operationKind,
                    'idempotency_key' => $idempotencyKey,
                    'state' => 'pending',
                    'requested_diff' => [
                        'deployment_resource_id' => $resource->getKey(),
                        'component_key' => $resource->component_key,
                        'provider' => 'proxmox',
                        'proxmox' => $component['proxmox'],
                    ],
                ]);
                $this->quota->attachReservations($reservations, $deployment, $operation);
                $this->auditPlacement($actor, $context, $deployment, $operation, $component, $placement);

                DeploymentStateTransition::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'deployment_id' => $deployment->getKey(),
                    'deployment_operation_id' => $operation->getKey(),
                    'from_state' => null,
                    'to_state' => 'pending',
                    'reason' => 'request_created',
                    'metadata' => [
                        'provider' => 'proxmox',
                    ],
                ]);

                $this->auditEvents->append([
                    'event_type' => 'deployment.request',
                    'action' => $operation->kind,
                    'result' => 'allowed',
                    'actor_type' => 'user',
                    'actor_id' => (string) $actor->id,
                    'actor_tenant' => $context->activeTenantId,
                    'resource_type' => 'deployment',
                    'resource_id' => $deployment->getKey(),
                    'resource_tenant' => $context->activeTenantId,
                    'target_tenant_set' => [$context->activeTenantId],
                    'effective_permissions' => ['deployment.create'],
                    'source_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'operation_id' => $operation->getKey(),
                        'project_id' => $project->getKey(),
                        'stack_definition_id' => $stack->getKey(),
                        'provider' => 'proxmox',
                        ...($lease->metadata === [] ? [] : ['lease' => $lease->metadata]),
                    ],
                ]);

                return [$deployment, $operation, $resource];
            });
        } catch (Throwable $throwable) {
            $this->quota->releaseReservations($reservations, 'proxmox_deployment_request_failed');

            throw $throwable;
        }

        /** @var Deployment $deployment */
        $deployment = $result[0];
        /** @var DeploymentOperation $operation */
        $operation = $result[1];
        /** @var DeploymentResource $resource */
        $resource = $result[2];
        $proxmox = $component['proxmox'];
        try {
            $task = $this->proxmox->requestClone($operation, new ProxmoxVmCloneRequest(
                node: $this->requiredString($proxmox, 'node'),
                templateVmid: $proxmox['template_vmid'],
                targetVmid: $proxmox['target_vmid'],
                name: $proxmox['name'],
                fullClone: $proxmox['full_clone'],
                storage: $proxmox['storage'],
            ));
        } catch (Throwable $throwable) {
            $this->quota->releaseForOperation($operation, 'proxmox_clone_request_failed');

            throw $throwable;
        }

        $task->forceFill([
            'metadata' => [
                ...($task->metadata ?? []),
                'deployment_resource_id' => $resource->getKey(),
                'component_key' => $resource->component_key,
            ],
        ])->save();

        return new DeploymentCreateResult($deployment->refresh(), $operation->refresh(), idempotentReplay: false);
    }

    public function operateRelease(
        User $actor,
        TenantContext $context,
        Deployment $deployment,
        string $idempotencyKey,
        Request $request,
    ): DeploymentCreateResult {
        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $actor->id),
            new Permission('deployment.update'),
            $deployment,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to release this deployment.');
        }

        /** @var DeploymentOperation|null $existingOperation */
        $existingOperation = DeploymentOperation::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingOperation instanceof DeploymentOperation) {
            /** @var Deployment $existingDeployment */
            $existingDeployment = Deployment::query()->whereKey($existingOperation->deployment_id)->firstOrFail();

            return new DeploymentCreateResult($existingDeployment, $existingOperation, idempotentReplay: true);
        }

        /** @var DeploymentResource|null $resource */
        $resource = DeploymentResource::query()
            ->where('deployment_id', $deployment->getKey())
            ->where('provider', 'proxmox')
            ->whereNotIn('state', ['released', 'removed'])
            ->orderBy('component_key')
            ->first();

        if (! $resource instanceof DeploymentResource) {
            throw new InvalidArgumentException('Proxmox release requires an active deployment resource.');
        }

        $node = $this->resourceNode($resource);
        $vmid = $this->resourceVmid($resource);

        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->whereKey($deployment->stack_definition_id)->firstOrFail();
        /** @var Project $project */
        $project = Project::query()->whereKey($deployment->project_id)->firstOrFail();

        $operation = DB::transaction(function () use ($actor, $context, $deployment, $project, $stack, $resource, $idempotencyKey, $request, $node, $vmid): DeploymentOperation {
            /** @var DeploymentOperation $operation */
            $operation = DeploymentOperation::query()->create([
                'tenant_id' => $context->activeTenantId,
                'deployment_id' => $deployment->getKey(),
                'project_id' => $project->getKey(),
                'stack_definition_id' => $stack->getKey(),
                'actor_user_id' => $actor->getKey(),
                'kind' => 'release',
                'idempotency_key' => $idempotencyKey,
                'state' => 'pending',
                'requested_diff' => [
                    'deployment_resource_id' => $resource->getKey(),
                    'provider' => 'proxmox',
                    'node' => $node,
                    'vmid' => $vmid,
                ],
            ]);

            $this->auditEvents->append([
                'event_type' => 'deployment.request',
                'action' => 'release',
                'result' => 'allowed',
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => 'deployment',
                'resource_id' => $deployment->getKey(),
                'resource_tenant' => $context->activeTenantId,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => ['deployment.update'],
                'source_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'operation_id' => $operation->getKey(),
                    'provider' => 'proxmox',
                    'deployment_resource_id' => $resource->getKey(),
                    'vmid' => $vmid,
                ],
            ]);

            return $operation;
        });

        $this->proxmox->requestDelete(
            operation: $operation,
            request: new ProxmoxVmDeleteRequest(node: $node, vmid: $vmid, purge: true),
            deploymentResourceId: $resource->resourceId(),
        );

        return new DeploymentCreateResult($deployment->refresh(), $operation->refresh(), idempotentReplay: false);
    }

    public function operatePower(
        User $actor,
        TenantContext $context,
        Deployment $deployment,
        string $operationKind,
        ?string $deploymentResourceId,
        string $idempotencyKey,
        Request $request,
    ): DeploymentCreateResult {
        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $actor->id),
            new Permission('deployment.power'),
            $deployment,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to power this deployment resource.');
        }

        /** @var DeploymentOperation|null $existingOperation */
        $existingOperation = DeploymentOperation::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingOperation instanceof DeploymentOperation) {
            /** @var Deployment $existingDeployment */
            $existingDeployment = Deployment::query()->whereKey($existingOperation->deployment_id)->firstOrFail();

            return new DeploymentCreateResult($existingDeployment, $existingOperation, idempotentReplay: true);
        }

        $resource = $this->powerResource($deployment, $deploymentResourceId);
        $node = $this->resourceNode($resource);
        $vmid = $this->resourceVmid($resource);
        $powerAction = match ($operationKind) {
            'power_on' => 'start',
            'power_off' => 'stop',
            default => throw new InvalidArgumentException('Unsupported Proxmox power operation.'),
        };

        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->whereKey($deployment->stack_definition_id)->firstOrFail();
        /** @var Project $project */
        $project = Project::query()->whereKey($deployment->project_id)->firstOrFail();

        $operation = DB::transaction(function () use ($actor, $context, $deployment, $project, $stack, $resource, $idempotencyKey, $request, $node, $vmid, $operationKind, $powerAction): DeploymentOperation {
            /** @var DeploymentOperation $operation */
            $operation = DeploymentOperation::query()->create([
                'tenant_id' => $context->activeTenantId,
                'deployment_id' => $deployment->getKey(),
                'project_id' => $project->getKey(),
                'stack_definition_id' => $stack->getKey(),
                'actor_user_id' => $actor->getKey(),
                'kind' => $operationKind,
                'idempotency_key' => $idempotencyKey,
                'state' => 'pending',
                'requested_diff' => [
                    'deployment_resource_id' => $resource->getKey(),
                    'provider' => 'proxmox',
                    'node' => $node,
                    'vmid' => $vmid,
                    'power_action' => $powerAction,
                ],
            ]);

            $this->auditEvents->append([
                'event_type' => 'deployment.request',
                'action' => $operationKind,
                'result' => 'allowed',
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => 'deployment',
                'resource_id' => $deployment->getKey(),
                'resource_tenant' => $context->activeTenantId,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => ['deployment.power'],
                'source_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'operation_id' => $operation->getKey(),
                    'provider' => 'proxmox',
                    'deployment_resource_id' => $resource->getKey(),
                    'vmid' => $vmid,
                    'power_action' => $powerAction,
                ],
            ]);

            return $operation;
        });

        $this->proxmox->requestPower(
            operation: $operation,
            request: new ProxmoxVmPowerRequest(node: $node, vmid: $vmid, action: $powerAction),
            deploymentResourceId: $resource->resourceId(),
        );

        return new DeploymentCreateResult($deployment->refresh(), $operation->refresh(), idempotentReplay: false);
    }

    /**
     * @return array{key: string, kind: string, proxmox: array{node: ?string, template_vmid: int, target_vmid: int, name: string, full_clone: bool, storage: ?string, cluster: ?string}, placement: array{required_vcpus: int, required_memory_mb: int, required_storage_gb: int, required_tags: list<string>}}
     */
    private function firstProxmoxComponent(StackDefinition $stack): array
    {
        $definition = $stack->definition ?? [];
        $components = is_array($definition['components'] ?? null) ? $definition['components'] : [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $component = $this->stringKeyedArray($component);

            if (($component['provider'] ?? $definition['provider'] ?? null) !== 'proxmox') {
                continue;
            }

            $proxmox = $this->stringKeyedArray($component['proxmox'] ?? null);

            return [
                'key' => $this->stringValue($component['key'] ?? null, 'vm'),
                'kind' => $this->stringValue($component['kind'] ?? null, 'vm'),
                'proxmox' => [
                    'node' => is_string($proxmox['node'] ?? null) && trim($proxmox['node']) !== ''
                        ? $proxmox['node']
                        : null,
                    'template_vmid' => $this->requiredInt($proxmox, 'template_vmid'),
                    'target_vmid' => $this->requiredInt($proxmox, 'target_vmid'),
                    'name' => $this->stringValue($proxmox['name'] ?? null, sprintf('racklab-%d', $this->requiredInt($proxmox, 'target_vmid'))),
                    'full_clone' => (bool) ($proxmox['full_clone'] ?? true),
                    'storage' => is_string($proxmox['storage'] ?? null) ? $proxmox['storage'] : null,
                    'cluster' => is_string($proxmox['cluster'] ?? null) ? $proxmox['cluster'] : null,
                ],
                'placement' => [
                    'required_vcpus' => $this->componentResourceInt($component, ['vcpus', 'vcpu', 'cpus', 'cpu'], 1),
                    'required_memory_mb' => $this->componentResourceInt($component, ['memory_mb', 'ram_mb', 'memory', 'ram'], 1024),
                    'required_storage_gb' => $this->componentResourceInt($component, ['storage_gb', 'disk_gb', 'disk'], 20),
                    'required_tags' => $this->stringList($component['tags'] ?? $proxmox['tags'] ?? []),
                ],
            ];
        }

        throw new InvalidArgumentException('Proxmox-backed stack definitions require a Proxmox VM component.');
    }

    private function resourceNode(DeploymentResource $resource): string
    {
        $metadata = $resource->metadata ?? [];
        $proxmox = is_array($metadata['proxmox'] ?? null) ? $metadata['proxmox'] : [];
        $node = $proxmox['node'] ?? null;

        if (is_string($node) && trim($node) !== '') {
            return $node;
        }

        throw new InvalidArgumentException('Proxmox resource metadata is missing node.');
    }

    /**
     * @param  array{key: string, kind: string, proxmox: array{node: ?string, template_vmid: int, target_vmid: int, name: string, full_clone: bool, storage: ?string, cluster: ?string}, placement: array{required_vcpus: int, required_memory_mb: int, required_storage_gb: int, required_tags: list<string>}}  $component
     */
    private function resolvePlacement(TenantContext $context, array $component): ?PlacementDecision
    {
        if (is_string($component['proxmox']['node']) && trim($component['proxmox']['node']) !== '') {
            return null;
        }

        return $this->scheduler->schedule($context, new PlacementRequest(
            provider: 'proxmox',
            requiredVcpus: $component['placement']['required_vcpus'],
            requiredMemoryMb: $component['placement']['required_memory_mb'],
            requiredStorageGb: $component['placement']['required_storage_gb'],
            templateVmid: $component['proxmox']['template_vmid'],
            providerCluster: $component['proxmox']['cluster'],
            requiredTags: $component['placement']['required_tags'],
        ));
    }

    /**
     * @param  array{key: string, kind: string, proxmox: array{node: ?string, template_vmid: int, target_vmid: int, name: string, full_clone: bool, storage: ?string, cluster: ?string}, placement: array{required_vcpus: int, required_memory_mb: int, required_storage_gb: int, required_tags: list<string>}}  $component
     */
    private function auditPlacement(
        User $actor,
        TenantContext $context,
        Deployment $deployment,
        DeploymentOperation $operation,
        array $component,
        ?PlacementDecision $placement,
    ): void {
        if (! $placement instanceof PlacementDecision) {
            return;
        }

        $this->auditEvents->append([
            'event_type' => 'deployment.scheduled',
            'action' => 'select',
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'deployment',
            'resource_id' => $deployment->getKey(),
            'resource_tenant' => $context->activeTenantId,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['deployment.create'],
            'metadata' => [
                'operation_id' => $operation->getKey(),
                'provider' => $placement->provider,
                'selected_node' => $placement->node,
                'candidate_nodes' => $placement->candidateNodes,
                'reasons' => $placement->reasons,
                'template_vmid' => $component['proxmox']['template_vmid'],
                'required_vcpus' => $component['placement']['required_vcpus'],
                'required_memory_mb' => $component['placement']['required_memory_mb'],
                'required_storage_gb' => $component['placement']['required_storage_gb'],
            ],
        ]);
    }

    private function resourceVmid(DeploymentResource $resource): int
    {
        if (is_string($resource->provider_resource_id) && ctype_digit($resource->provider_resource_id)) {
            return (int) $resource->provider_resource_id;
        }

        throw new InvalidArgumentException('Proxmox resource is missing provider VMID.');
    }

    private function powerResource(Deployment $deployment, ?string $deploymentResourceId): DeploymentResource
    {
        $query = DeploymentResource::query()
            ->where('deployment_id', $deployment->getKey())
            ->where('provider', 'proxmox')
            ->whereNotIn('state', ['released', 'removed']);

        if (is_string($deploymentResourceId) && trim($deploymentResourceId) !== '') {
            $query->whereKey($deploymentResourceId);
        }

        /** @var DeploymentResource|null $resource */
        $resource = $query->orderBy('component_key')->first();

        if (! $resource instanceof DeploymentResource) {
            throw new InvalidArgumentException('Proxmox power operation requires an active deployment resource.');
        }

        return $resource;
    }

    private function leaseDurationMinutes(Request $request): ?int
    {
        $value = $request->input('lease_duration_minutes');

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Missing Proxmox %s value.', $key));
        }

        return $value;
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

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf('Missing Proxmox %s value.', $key));
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  list<string>  $keys
     */
    private function componentResourceInt(array $component, array $keys, int $default): int
    {
        $resources = $this->stringKeyedArray($component['resources'] ?? null);

        foreach ([$resources, $component] as $values) {
            foreach ($keys as $key) {
                $value = $values[$key] ?? null;

                if (is_int($value)) {
                    return max(0, $value);
                }

                if (is_string($value) && ctype_digit($value)) {
                    return (int) $value;
                }
            }
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
    }
}
