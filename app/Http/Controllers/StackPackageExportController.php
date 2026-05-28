<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use App\Stacks\StackPackageExporter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a project-local StackDefinition as a downloadable RackLab Stack
 * Package. Authorized by `catalog.stack_package.export` on the stack's owning
 * project (personal-project owners hold this through their project admin role),
 * so a member can export their own stacks but not another project's. A
 * not-found is returned on denial to avoid leaking stack existence.
 */
final class StackPackageExportController extends Controller
{
    public function __invoke(
        Request $request,
        string $stack,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        StackPackageExporter $exporter,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var StackDefinition|null $definition */
        $definition = StackDefinition::query()->whereKey($stack)->first();

        if (! $definition instanceof StackDefinition || $definition->project_id === null) {
            throw new NotFoundHttpException('Stack not found.');
        }

        /** @var Project|null $project */
        $project = Project::query()->whereKey($definition->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Stack not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('catalog.stack_package.export'),
            $project,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Stack not found.');
        }

        $package = $exporter->export(
            name: $definition->name,
            slug: $definition->slug,
            definition: $definition->definition ?? [],
        );

        return new Response($package->bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$package->filename.'"',
        ]);
    }
}
