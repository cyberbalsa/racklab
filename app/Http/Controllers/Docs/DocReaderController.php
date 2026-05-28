<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docs;

use App\Audit\AuditEventWriter;
use App\Docs\DocVisibilityPolicy;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Doc;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser-facing docs reader. Renders the published (or owner-draft)
 * HTML cache and wires the `racklab-ref` status-pill island, which
 * upgrades each `<racklab-ref>` cross-link against the resolver endpoint.
 *
 * Authorization mirrors `Api\DocShowController` (session auth, no token
 * abilities): gate `docs.view` against the parent Project through
 * `AccessResolver`, apply the draft/publish visibility policy, and 404
 * — never 403 — on deny so existence is not leaked. Denied reads emit a
 * `docs.page` denied audit row.
 */
final class DocReaderController extends Controller
{
    private const string PERMISSION = 'docs.view';

    public function __invoke(
        Request $request,
        Doc $doc,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        AuditEventWriter $auditEvents,
        DocVisibilityPolicy $visibility,
    ): View {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if ($doc->tenant_id !== $context->activeTenantId) {
            throw new NotFoundHttpException('Doc not found.');
        }

        $project = $this->project($doc);

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $decision->allowed) {
            $this->auditDenied($auditEvents, $user, $context, $doc, $project, 'permission');

            throw new NotFoundHttpException('Doc not found.');
        }

        if (! $visibility->canRead($user, $doc, $project, $context)) {
            $this->auditDenied($auditEvents, $user, $context, $doc, $project, 'draft_hidden');

            throw new NotFoundHttpException('Doc not found.');
        }

        return view('docs.show', [
            'doc' => $doc->load('currentVersion'),
        ]);
    }

    private function project(Doc $doc): Project
    {
        /** @var Project|null $project */
        $project = $doc->project_id === null
            ? null
            : Project::query()->whereKey($doc->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Doc not found.');
        }

        return $project;
    }

    private function auditDenied(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Doc $doc,
        Project $project,
        string $reason,
    ): void {
        $auditEvents->append([
            'event_type' => 'docs.page',
            'action' => 'read',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $doc->resourceType(),
            'resource_id' => $doc->resourceId(),
            'resource_tenant' => $doc->tenant_id,
            'target_tenant_set' => [$context->activeTenantId, $doc->tenant_id],
            'effective_permissions' => [self::PERMISSION],
            'metadata' => [
                'reason' => $reason,
                'project_id' => $project->getKey(),
                'surface' => 'web_reader',
            ],
        ]);
    }
}
