<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditEventWriter;
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
        AuditEventWriter $auditEvents,
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
            $this->audit($auditEvents, $user, $context, $definition, 'denied');

            throw new NotFoundHttpException('Stack not found.');
        }

        $package = $exporter->export(
            name: $definition->name,
            slug: $definition->slug,
            definition: $definition->definition ?? [],
        );

        $this->audit($auditEvents, $user, $context, $definition, 'allowed');

        return new Response($package->bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$package->filename.'"',
        ]);
    }

    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        StackDefinition $stack,
        string $result,
    ): void {
        $auditEvents->append([
            'event_type' => 'catalog.stack_package.export',
            'action' => 'export',
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $stack->resourceType(),
            'resource_id' => $stack->resourceId(),
            'resource_tenant' => $stack->tenant_id,
            'target_tenant_set' => [$stack->tenant_id],
            'effective_permissions' => ['catalog.stack_package.export'],
            'metadata' => ['stack_slug' => $stack->slug],
        ]);
    }
}
