<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSecurityGroupRequest;
use App\Models\Project;
use App\Models\SecurityGroup;
use App\Models\SecurityGroupRule;
use App\Models\User;
use App\Networking\NetworkQuotaService;
use App\Networking\SecurityGroupPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SecurityGroupStoreController extends Controller
{
    private const string PERMISSION = 'network.manage_security_group';

    public function __invoke(
        StoreSecurityGroupRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        NetworkQuotaService $networkQuota,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $project = $this->project($request->string('project_id')->toString());
        $rules = $this->rules($request->input('rules'));
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'create', 'denied', [
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to manage security groups.');
        }

        $quotaLimits = $networkQuota->assertSecurityGroupRuleCapacity(
            actor: $user,
            context: $context,
            project: $project,
            ruleCount: count($rules),
            operationKind: 'network.security_group.create',
        );

        $securityGroup = DB::transaction(function () use ($request, $context, $user, $project, $rules, $quotaLimits, $networkQuota, $auditEvents): SecurityGroup {
            /** @var SecurityGroup $securityGroup */
            $securityGroup = SecurityGroup::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->getKey(),
                'name' => $request->string('name')->toString(),
                'slug' => $request->string('slug')->toString(),
                'state' => 'active',
                'provider' => 'fake',
                'provider_security_group_id' => null,
                'metadata' => $this->stringKeyedArray($request->input('metadata')),
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ]);

            $securityGroup->forceFill([
                'provider_security_group_id' => 'fake-sg-'.$securityGroup->id,
            ])->save();

            $this->replaceRules($securityGroup, $rules, revision: 1);
            $networkQuota->replaceSecurityGroupRuleUsage($quotaLimits, $securityGroup, $user, count($rules), 'network.security_group.create');
            $this->audit($auditEvents, $user, $context, $project, 'create', 'allowed', [
                'security_group_id' => $securityGroup->getKey(),
                'rule_count' => count($rules),
                'provider' => 'fake',
            ]);

            return $securityGroup;
        });

        return response()->json(['data' => SecurityGroupPayload::make($securityGroup->refresh())], 201);
    }

    private function project(string $projectId): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }

    /**
     * @return list<array{direction: string, protocol: string, ethertype: string, port_min: ?int, port_max: ?int, remote_cidr: ?string}>
     */
    private function rules(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rules = [];

        foreach ($value as $rawRule) {
            if (! is_array($rawRule)) {
                continue;
            }

            $rule = $this->stringKeyedArray($rawRule);
            $portMin = $rule['port_min'] ?? null;
            $portMax = $rule['port_max'] ?? null;
            $remoteCidr = $rule['remote_cidr'] ?? null;

            $rules[] = [
                'direction' => is_string($rule['direction'] ?? null) ? $rule['direction'] : 'ingress',
                'protocol' => is_string($rule['protocol'] ?? null) ? $rule['protocol'] : 'any',
                'ethertype' => 'IPv4',
                'port_min' => is_numeric($portMin) ? (int) $portMin : null,
                'port_max' => is_numeric($portMax) ? (int) $portMax : null,
                'remote_cidr' => is_string($remoteCidr) && $remoteCidr !== '' ? $remoteCidr : null,
            ];
        }

        return $rules;
    }

    /**
     * @param  list<array{direction: string, protocol: string, ethertype: string, port_min: ?int, port_max: ?int, remote_cidr: ?string}>  $rules
     */
    private function replaceRules(SecurityGroup $securityGroup, array $rules, int $revision): void
    {
        SecurityGroupRule::query()->where('security_group_id', $securityGroup->getKey())->delete();

        foreach ($rules as $index => $rule) {
            /** @var SecurityGroupRule $model */
            $model = SecurityGroupRule::query()->create([
                'tenant_id' => $securityGroup->tenant_id,
                'security_group_id' => $securityGroup->getKey(),
                'position' => $index + 1,
                'direction' => $rule['direction'],
                'protocol' => $rule['protocol'],
                'ethertype' => $rule['ethertype'],
                'port_min' => $rule['port_min'],
                'port_max' => $rule['port_max'],
                'remote_cidr' => $rule['remote_cidr'],
                'state' => 'active',
                'provider_rule_id' => null,
                'provider_binding' => [
                    'provider' => 'fake',
                    'mode' => 'fake-firewall-rule',
                    'revision' => $revision,
                    'position' => $index + 1,
                ],
                'metadata' => [],
            ]);

            $model->forceFill(['provider_rule_id' => 'fake-sg-rule-'.$model->id])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Project $project,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'network.security_group',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [self::PERMISSION],
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
}
