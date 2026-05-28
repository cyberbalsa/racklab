<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Tenancy\DefaultTenantContextResolver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use RuntimeException;

final readonly class AuditAuthEvent
{
    public function __construct(
        private AuditEventWriter $auditEvents,
        private DefaultTenantContextResolver $tenantContext,
    ) {}

    public function handle(Registered|Login|Failed|Logout|PasswordUpdatedViaController $event): void
    {
        $user = $this->userFromEvent($event);
        $context = $this->resolveTenantContext($user);

        if (! $context instanceof TenantContext) {
            return;
        }

        $request = request();
        $metadata = $this->metadata($event);
        $eventType = $this->eventType($event);

        $this->auditEvents->append([
            'event_type' => $eventType,
            'action' => $this->action($event),
            'result' => $event instanceof Failed ? 'denied' : 'allowed',
            'actor_type' => $user instanceof User ? 'user' : 'anonymous',
            'actor_id' => $user instanceof User ? (string) $user->id : $this->anonymousActorId($metadata),
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $user instanceof User ? 'user' : 'auth_attempt',
            'resource_id' => $user instanceof User ? (string) $user->id : null,
            'resource_tenant' => $context->activeTenantId,
            'target_tenant_set' => [$context->activeTenantId],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    private function userFromEvent(Registered|Login|Failed|Logout|PasswordUpdatedViaController $event): ?User
    {
        $user = $event->user;

        return $user instanceof User ? $user : null;
    }

    private function resolveTenantContext(?User $user): ?TenantContext
    {
        try {
            return $this->tenantContext->resolve($user);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function eventType(Registered|Login|Failed|Logout|PasswordUpdatedViaController $event): string
    {
        return match (true) {
            $event instanceof Registered => 'auth.signup',
            $event instanceof Login => 'auth.login',
            $event instanceof Failed => 'auth.failed_login',
            $event instanceof Logout => 'auth.logout',
            $event instanceof PasswordUpdatedViaController => 'auth.password_change',
        };
    }

    private function action(Registered|Login|Failed|Logout|PasswordUpdatedViaController $event): string
    {
        return match (true) {
            $event instanceof Registered => 'signup',
            $event instanceof Login, $event instanceof Failed => 'login',
            $event instanceof Logout => 'logout',
            $event instanceof PasswordUpdatedViaController => 'password_change',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Registered|Login|Failed|Logout|PasswordUpdatedViaController $event): array
    {
        if ($event instanceof Login) {
            return [
                'guard' => $event->guard,
                'remember' => $event->remember,
            ];
        }

        if ($event instanceof Failed) {
            return [
                'guard' => $event->guard,
                'email_hash' => $this->emailHash($event->credentials['email'] ?? null),
            ];
        }

        if ($event instanceof Logout) {
            return ['guard' => $event->guard];
        }

        return ['guard' => 'web'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function anonymousActorId(array $metadata): string
    {
        $emailHash = $metadata['email_hash'] ?? null;

        return is_string($emailHash) ? 'anonymous:'.$emailHash : 'anonymous';
    }

    private function emailHash(mixed $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }
}
