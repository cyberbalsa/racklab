<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docs;

use App\Audit\AuditEventWriter;
use App\Docs\Refs\RackLabRef;
use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolveAuditSampler;
use App\Docs\Refs\Resolving\RefResolverRegistry;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a single `[[kind:id]]` cross-link reference into a live,
 * RBAC-filtered status payload for the docs reader's status-pill island.
 *
 * Authorization is per-kind inside each `RefResolver`: a target the
 * actor cannot read is returned as `redacted` (never leaking its label
 * or live state), a missing target as `not_found`, and a kind with no
 * registered resolver as `unsupported`. Every resolution is audited via
 * a sampler that always records the security-relevant outcomes and
 * samples the high-volume successful ones.
 */
final class RefResolveController extends Controller
{
    public function __invoke(
        Request $request,
        string $kind,
        string $id,
        TenantContextStore $tenantContext,
        RefResolverRegistry $registry,
        RefResolveAuditSampler $sampler,
        AuditEventWriter $auditEvents,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        // Reject references that do not match the grammar before touching
        // any resolver, so a malformed kind/id cannot probe the registry.
        try {
            $ref = new RackLabRef(kind: $kind, id: $id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException('Reference not found.');
        }

        $resolver = $registry->resolverFor($ref->kind);

        $result = $resolver instanceof \App\Docs\Refs\Resolving\RefResolver
            ? $resolver->resolve(
                new RefResolutionContext(new ActorIdentity((string) $user->id), $context),
                $ref->id,
            )
            : ResolvedRef::unsupported($ref->kind, $ref->id);

        if ($sampler->shouldRecord($result->status)) {
            $this->audit($auditEvents, $user, $context, $result);
        }

        return response()->json(['data' => $result->toArray()]);
    }

    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        ResolvedRef $result,
    ): void {
        $auditEvents->append([
            'event_type' => 'docs.ref_resolve',
            'action' => 'resolve',
            'result' => $result->isVisible() ? 'allowed' : 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $result->kind,
            'resource_id' => $result->id,
            'resource_tenant' => $context->activeTenantId,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [],
            'metadata' => [
                'ref_kind' => $result->kind,
                'ref_id' => $result->id,
                'resolution_status' => $result->status->value,
                'rbac_visible' => $result->isVisible(),
            ],
        ]);
    }
}
