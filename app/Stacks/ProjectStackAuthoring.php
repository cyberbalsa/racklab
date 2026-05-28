<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Authors project-local StackDefinitions for the browser stack builder. A
 * project-local stack is the user's own blueprint (one or more VM components,
 * each attaching tenant network offerings). Authoring is gated by
 * `project.update` on the owning project through AccessResolver, mirroring the
 * JSON project-stack API so the two paths share the same policy.
 */
final readonly class ProjectStackAuthoring
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function create(
        User $user,
        TenantContext $context,
        Project $project,
        string $name,
        array $definition,
    ): StackDefinition {
        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.update'),
            $project,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to author stacks in this project.');
        }

        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->create([
            'tenant_id' => $context->activeTenantId,
            'project_id' => $project->id,
            'name' => $name,
            'slug' => $this->uniqueSlug($context->activeTenantId, $project->id, $name),
            'scope' => 'project_local',
            'is_reserved_default' => false,
            'definition' => $definition,
            'sharing_scope' => 'tenant_local',
            'shared_with_tenants' => [],
        ]);

        return $stack;
    }

    private function uniqueSlug(string $tenantId, string $projectId, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'stack';
        }

        $candidate = $base;
        $suffix = 1;

        while (StackDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('slug', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }
}
