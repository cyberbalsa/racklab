<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Models\Deployment;
use App\Models\RoleBinding;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shares a deployment's VM console with other tenant members: each guest gets a
 * per-deployment `console_guest` role binding (read + console-connect only).
 * The use case is one group of students granting another group console access
 * to their lab VM. Sharing is gated by `deployment.update` on the deployment
 * (the owner/manager), and guests can only be tenant members (no cross-tenant
 * or unknown-email grants). Every share/revoke is audited.
 */
final readonly class ConsoleShareService
{
    private const string GUEST_ROLE = 'console_guest';

    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
    ) {}

    public function share(User $actor, TenantContext $context, Deployment $deployment, string $rawEmails): ConsoleShareResult
    {
        $this->authorizeManage($actor, $context, $deployment);

        $shared = 0;
        $already = 0;
        $missing = [];

        foreach ($this->emails($rawEmails) as $email) {
            /** @var User|null $member */
            $member = User::query()
                ->whereRaw('lower(email) = ?', [$email])
                ->whereIn('id', TenantMembership::query()
                    ->where('tenant_id', $context->activeTenantId)
                    ->select('user_id'))
                ->first();

            if (! $member instanceof User) {
                $missing[] = $email;

                continue;
            }

            $binding = RoleBinding::query()->firstOrCreate(
                [
                    'principal_type' => 'user',
                    'principal_id' => (string) $member->id,
                    'resource_type' => $deployment->resourceType(),
                    'resource_id' => $deployment->resourceId(),
                    'role' => self::GUEST_ROLE,
                ],
                [
                    'scope_type' => RoleBindingScopeType::TenantLocal,
                    'tenant_id' => $context->activeTenantId,
                    'tenant_set' => [$context->activeTenantId],
                    'granted_by_id' => $actor->getKey(),
                    'granted_reason' => 'console share',
                ],
            );

            if ($binding->wasRecentlyCreated) {
                $shared++;
                $this->audit($actor, $context, $deployment, 'share', $member->id);
            } else {
                $already++;
            }
        }

        return new ConsoleShareResult($shared, $already, $missing);
    }

    public function revoke(User $actor, TenantContext $context, Deployment $deployment, int $userId): void
    {
        $this->authorizeManage($actor, $context, $deployment);

        $deleted = RoleBinding::query()
            ->where('principal_type', 'user')
            ->where('principal_id', (string) $userId)
            ->where('resource_type', $deployment->resourceType())
            ->where('resource_id', $deployment->resourceId())
            ->where('role', self::GUEST_ROLE)
            ->delete();

        if ($deleted > 0) {
            $this->audit($actor, $context, $deployment, 'revoke', $userId);
        }
    }

    private function authorizeManage(User $actor, TenantContext $context, Deployment $deployment): void
    {
        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $actor->id),
            new Permission('deployment.update'),
            $deployment,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to share this deployment console.');
        }
    }

    private function audit(User $actor, TenantContext $context, Deployment $deployment, string $action, int $guestId): void
    {
        $this->auditEvents->append([
            'event_type' => 'deployment.console.share',
            'action' => $action,
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $deployment->resourceType(),
            'resource_id' => $deployment->resourceId(),
            'resource_tenant' => $deployment->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['deployment.console.connect'],
            'metadata' => ['guest_user_id' => $guestId],
        ]);
    }

    /**
     * @return list<string>
     */
    private function emails(string $raw): array
    {
        $emails = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $email = mb_strtolower(trim($line));

            if ($email !== '' && ! in_array($email, $emails, strict: true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }
}
