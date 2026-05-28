<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\PlatformResource;
use App\Models\User;

/**
 * Authorization gate for the /horizon dashboard.
 *
 * Delegates to AccessResolver::permittedPlatform() — the only sanctioned
 * platform-scope authorization entry point. Emits hash-chained audit events
 * for both allow and deny paths so anonymous probes remain visible.
 *
 * Wired by HorizonServiceProvider::boot() via Horizon::auth(); the callback
 * receives a Request and forwards $request->user() here (which may be null).
 */
final readonly class HorizonAuthGate
{
    public function __construct(
        private AccessResolver $resolver,
        private AuditEventWriter $audit,
    ) {}

    public function authorize(?User $user): bool
    {
        if (! $user instanceof User) {
            $this->emitDenied(actorId: null, reason: 'anonymous');

            return false;
        }

        $decision = $this->decide($user);

        if (! $decision->allowed) {
            $reason = $decision->denyReason instanceof \App\Domain\Tenancy\AccessDenyReason ? $decision->denyReason->value : 'unknown';
            $this->emitDenied(actorId: (string) $user->id, reason: $reason);

            return false;
        }

        $this->emitAllowed(actorId: (string) $user->id);

        return true;
    }

    /**
     * Non-auditing variant — same predicate, no audit-row side effect.
     *
     * Use when rendering UI affordances (e.g. a "Horizon" nav link in
     * Filament) that should be hidden when the user can't access /horizon.
     * The authoritative gate that DOES audit is {@see authorize()}.
     */
    public function canView(?User $user): bool
    {
        return $user instanceof User && $this->decide($user)->allowed;
    }

    private function decide(User $user): \App\Domain\Tenancy\AccessDecision
    {
        return $this->resolver->permittedPlatform(
            new ActorIdentity((string) $user->id),
            new Permission('horizon.view'),
        );
    }

    private function emitAllowed(string $actorId): void
    {
        $this->audit->append([
            'event_type' => 'horizon.access',
            'action' => 'horizon.view',
            'result' => 'allow',
            'actor_type' => 'user',
            'actor_id' => $actorId,
            'actor_tenant' => null,
            'resource_type' => PlatformResource::RESOURCE_TYPE,
            'resource_id' => PlatformResource::RACKLAB_ID,
            'resource_tenant' => null,
            'metadata' => ['user_id' => $actorId],
        ]);
    }

    private function emitDenied(?string $actorId, string $reason): void
    {
        $this->audit->append([
            'event_type' => 'horizon.access.denied',
            'action' => 'horizon.view',
            'result' => 'deny',
            'actor_type' => $actorId === null ? 'anonymous' : 'user',
            'actor_id' => $actorId ?? 'anonymous',
            'actor_tenant' => null,
            'resource_type' => PlatformResource::RESOURCE_TYPE,
            'resource_id' => PlatformResource::RACKLAB_ID,
            'resource_tenant' => null,
            'metadata' => ['reason' => $reason],
        ]);
    }
}
