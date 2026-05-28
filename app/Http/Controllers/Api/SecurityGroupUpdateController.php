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
use App\Http\Requests\Api\UpdateSecurityGroupRequest;
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

final class SecurityGroupUpdateController extends Controller
{
    private const string PERMISSION = 'network.manage_security_group';

    public function __invoke(
        UpdateSecurityGroupRequest $request,
        string $securityGroup,
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

        /** @var SecurityGroup|null $model */
        $model = SecurityGroup::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($securityGroup)
            ->first();

        if (! $model instanceof SecurityGroup) {
            throw new NotFoundHttpException('Security group not found.');
        }

        /** @var Project|null $project */
        $project = Project::query()->whereKey($model->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'update', 'denied', [
                'security_group_id' => $model->getKey(),
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to manage security groups.');
        }

        $rules = $this->rules($request->input('rules'));
        $existingQuantity = $networkQuota->activeSecurityGroupRuleQuantity($model);
        $quotaLimits = $networkQuota->assertSecurityGroupRuleCapacity(
            actor: $user,
            context: $context,
            project: $project,
            ruleCount: count($rules),
            operationKind: 'network.security_group.update',
            existingQuantity: $existingQuantity,
        );

        $updated = DB::transaction(function () use ($request, $model, $rules, $quotaLimits, $networkQuota, $user, $auditEvents, $context, $project): SecurityGroup {
            $updates = [];

            if ($request->has('name')) {
                $updates['name'] = $request->string('name')->toString();
            }

            if ($request->has('metadata')) {
                $updates['metadata'] = $this->stringKeyedArray($request->input('metadata'));
            }

            if ($updates !== []) {
                $model->forceFill($updates)->save();
            }

            $this->replaceRules($model, $rules, revision: 2);
            $networkQuota->replaceSecurityGroupRuleUsage($quotaLimits, $model, $user, count($rules), 'network.security_group.update');
            $this->audit($auditEvents, $user, $context, $project, 'update', 'allowed', [
                'security_group_id' => $model->getKey(),
                'rule_count' => count($rules),
                'provider' => $model->provider,
            ]);

            return $model;
        });

        return response()->json(['data' => SecurityGroupPayload::make($updated->refresh())]);
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
                    'provider' => $securityGroup->provider,
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
