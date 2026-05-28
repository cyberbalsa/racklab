<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Models\CatalogVersion;
use App\Models\Project;
use App\Models\ProjectDefaultStack;
use App\Models\StackDefinition;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DefaultStackResolver
{
    public function forProject(Project $project, ?string $stackDefinitionId = null): StackDefinition
    {
        if (is_string($stackDefinitionId) && trim($stackDefinitionId) !== '') {
            /** @var StackDefinition|null $stack */
            $stack = StackDefinition::query()
                ->whereKey($stackDefinitionId)
                ->where('project_id', $project->getKey())
                ->first();

            if (! $stack instanceof StackDefinition) {
                throw new NotFoundHttpException('Stack definition not found.');
            }

            return $stack;
        }

        /** @var ProjectDefaultStack|null $pointer */
        $pointer = ProjectDefaultStack::query()
            ->where('project_id', $project->getKey())
            ->first();

        if (! $pointer instanceof ProjectDefaultStack) {
            throw new NotFoundHttpException('Default stack not found.');
        }

        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->whereKey($pointer->stack_definition_id)->firstOrFail();

        return $stack;
    }

    public function forProjectOrCatalogVersion(
        Project $project,
        ?string $stackDefinitionId = null,
        ?string $catalogVersionId = null,
    ): StackDefinition {
        if (is_string($catalogVersionId) && trim($catalogVersionId) !== '') {
            /** @var CatalogVersion|null $version */
            $version = CatalogVersion::query()
                ->whereKey($catalogVersionId)
                ->where('state', 'published')
                ->first();

            if (! $version instanceof CatalogVersion) {
                throw new NotFoundHttpException('Catalog version not found.');
            }

            /** @var StackDefinition $stack */
            $stack = StackDefinition::query()->whereKey($version->stack_definition_id)->firstOrFail();

            return $stack;
        }

        return $this->forProject($project, $stackDefinitionId);
    }
}
