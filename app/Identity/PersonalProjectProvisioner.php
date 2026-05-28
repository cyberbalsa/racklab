<?php

declare(strict_types=1);

namespace App\Identity;

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\ProjectDefaultStack;
use App\Models\ProjectMembership;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;

final readonly class PersonalProjectProvisioner
{
    public function ensureFor(User $user, TenantContext $context): Project
    {
        return DB::transaction(function () use ($user, $context): Project {
            $userId = $user->id;

            UserProfile::query()->firstOrCreate(
                ['user_id' => $userId],
                [
                    'display_name' => $user->name,
                    'locale' => 'en',
                ],
            );

            TenantMembership::query()->firstOrCreate(
                [
                    'tenant_id' => $context->activeTenantId,
                    'user_id' => $userId,
                ],
                [
                    'is_primary' => ! TenantMembership::query()
                        ->where('user_id', $userId)
                        ->where('is_primary', true)
                        ->exists(),
                ],
            );

            $project = Project::query()->firstOrCreate(
                [
                    'tenant_id' => $context->activeTenantId,
                    'created_for_user_id' => $userId,
                    'is_personal_default' => true,
                ],
                [
                    'name' => sprintf('%s Personal Project', $user->name),
                    'slug' => sprintf('personal-%d', $userId),
                    'sharing_scope' => 'tenant_local',
                    'shared_with_tenants' => [],
                ],
            );

            ProjectMembership::query()->firstOrCreate(
                [
                    'tenant_id' => $context->activeTenantId,
                    'project_id' => $project->id,
                    'user_id' => $userId,
                ],
                ['role' => 'owner'],
            );

            // Tenant-scoped baseline membership binding. Grants the minimal
            // tenant_member role (catalog browsing) to every member, so the
            // catalog is readable without a per-item grant. AccessResolver
            // still gates visibility + permission on top of this binding.
            RoleBinding::query()->firstOrCreate(
                [
                    'principal_type' => 'user',
                    'principal_id' => (string) $userId,
                    'resource_type' => 'tenant',
                    'resource_id' => $context->activeTenantId,
                ],
                [
                    'role' => 'tenant_member',
                    'scope_type' => RoleBindingScopeType::TenantLocal,
                    'tenant_id' => $context->activeTenantId,
                    'tenant_set' => [],
                    'granted_by_id' => $userId,
                    'granted_reason' => 'tenant membership baseline',
                ],
            );

            RoleBinding::query()->firstOrCreate(
                [
                    'principal_type' => 'user',
                    'principal_id' => (string) $userId,
                    'resource_type' => 'project',
                    'resource_id' => $project->resourceId(),
                ],
                [
                    'role' => 'admin',
                    'scope_type' => RoleBindingScopeType::TenantLocal,
                    'tenant_id' => $context->activeTenantId,
                    'tenant_set' => [],
                    'granted_by_id' => $userId,
                    'granted_reason' => 'first-login personal project owner',
                ],
            );

            $stack = StackDefinition::query()->firstOrCreate(
                [
                    'tenant_id' => $context->activeTenantId,
                    'project_id' => $project->id,
                    'slug' => 'default',
                ],
                [
                    'name' => 'Default',
                    'scope' => 'project_local',
                    'is_reserved_default' => true,
                    'definition' => [
                        'version' => 1,
                        'components' => [],
                    ],
                    'sharing_scope' => 'tenant_local',
                    'shared_with_tenants' => [],
                ],
            );

            ProjectDefaultStack::query()->firstOrCreate(
                [
                    'tenant_id' => $context->activeTenantId,
                    'project_id' => $project->id,
                ],
                [
                    'stack_definition_id' => $stack->id,
                    'active_deployment_id' => null,
                ],
            );

            return $project->refresh();
        });
    }
}
