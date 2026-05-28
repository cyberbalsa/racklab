<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving\Core;

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Models\Project;

/**
 * Core resolver for `[[project:id]]` cross-links. Projects have no
 * lifecycle state, so the pill carries a label and link but no detail.
 */
final readonly class ProjectRefResolver implements RefResolver
{
    public function __construct(private AccessResolver $access) {}

    public function kind(): string
    {
        return 'project';
    }

    public function resolve(RefResolutionContext $context, string $id): ResolvedRef
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($id)->first();

        if (! $project instanceof Project) {
            return ResolvedRef::notFound($this->kind(), $id);
        }

        $decision = $this->access->permitted(
            $context->actor,
            new Permission('project.read'),
            $project,
            $context->tenant,
        );

        if (! $decision->allowed) {
            return ResolvedRef::redacted($this->kind(), $id);
        }

        // No public project detail page yet (M10a); render a non-link pill.
        return ResolvedRef::resolved($this->kind(), $id, $project->name, null, null);
    }
}
