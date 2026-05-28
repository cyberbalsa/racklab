<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\TokenGrant;
use App\Models\TokenRevocation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class TrackBTokenService
{
    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
    ) {}

    /**
     * @param  list<string>  $abilities
     */
    public function issue(
        User $issuer,
        TenantContext $context,
        Project $project,
        string $name,
        array $abilities,
        ?CarbonInterface $expiresAt,
        Request $request,
    ): TrackBTokenIssue {
        $abilities = $this->normalizeAbilities($abilities);
        $actor = new ActorIdentity((string) $issuer->id);

        $createDecision = $this->accessResolver->permitted(
            $actor,
            new Permission('token.create'),
            $project,
            $context,
        );

        if (! $createDecision->allowed) {
            $this->auditIssueDenied($issuer, $context, $project, $abilities, 'token_create_not_granted', $request);

            throw new AuthorizationException('You are not allowed to create tokens for this project.');
        }

        foreach ($abilities as $ability) {
            $abilityDecision = $this->accessResolver->permitted(
                $actor,
                new Permission($ability),
                $project,
                $context,
            );

            if (! $abilityDecision->allowed) {
                $this->auditIssueDenied($issuer, $context, $project, $abilities, 'ability_not_granted', $request);

                throw ValidationException::withMessages([
                    'abilities' => sprintf('The token ability [%s] exceeds the issuer permissions for this project.', $ability),
                ]);
            }
        }

        return DB::transaction(function () use ($issuer, $context, $project, $name, $abilities, $expiresAt, $request): TrackBTokenIssue {
            $newToken = $issuer->createToken($name, $abilities, $expiresAt);
            /** @var PersonalAccessToken $sanctumToken */
            $sanctumToken = $newToken->accessToken;

            /** @var TokenGrant $grant */
            $grant = TokenGrant::query()->create([
                'tenant_id' => $context->activeTenantId,
                'owner_user_id' => $issuer->id,
                'created_by_id' => $issuer->id,
                'sanctum_token_id' => $sanctumToken->getKey(),
                'name' => $name,
                'track' => 'pat',
                'scope_type' => RoleBindingScopeType::TenantLocal,
                'tenant_set' => [],
                'resource_type' => $project->resourceType(),
                'resource_id' => $project->resourceId(),
                'abilities' => $abilities,
                'allowed_ip_cidrs' => [],
                'expires_at' => $expiresAt,
            ]);

            $this->auditEvents->append([
                'event_type' => 'token.grant',
                'action' => 'create',
                'result' => 'allowed',
                'actor_type' => 'user',
                'actor_id' => (string) $issuer->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $grant->resourceType(),
                'resource_id' => $grant->resourceId(),
                'resource_tenant' => $context->activeTenantId,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => $abilities,
                'source_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'track' => 'pat',
                    'scope_type' => RoleBindingScopeType::TenantLocal->value,
                    'resource_type' => $project->resourceType(),
                    'resource_id' => $project->resourceId(),
                ],
            ]);

            return new TrackBTokenIssue($grant, $newToken->plainTextToken);
        });
    }

    public function revoke(User $actor, TenantContext $context, TokenGrant $grant, Request $request): void
    {
        if ($grant->owner_user_id !== $actor->id) {
            throw new AuthorizationException('You are not allowed to revoke this token.');
        }

        DB::transaction(function () use ($actor, $context, $grant, $request): void {
            $sanctumTokenId = $grant->sanctum_token_id;

            if ($grant->revoked_at === null) {
                $grant->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_id' => $actor->id,
                    'sanctum_token_id' => null,
                ])->save();

                TokenRevocation::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'token_grant_id' => $grant->id,
                    'revoked_by_id' => $actor->id,
                    'reason' => 'user_request',
                    'revoked_at' => $grant->revoked_at,
                ]);
            }

            if ($sanctumTokenId !== null) {
                PersonalAccessToken::query()
                    ->whereKey($sanctumTokenId)
                    ->delete();
            }

            $this->auditEvents->append([
                'event_type' => 'token.grant',
                'action' => 'revoke',
                'result' => 'allowed',
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $grant->resourceType(),
                'resource_id' => $grant->resourceId(),
                'resource_tenant' => $grant->tenant_id,
                'target_tenant_set' => [$grant->tenant_id],
                'effective_permissions' => $grant->abilities,
                'source_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'track' => 'pat',
                    'reason' => 'user_request',
                ],
            ]);
        });
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private function normalizeAbilities(array $abilities): array
    {
        $normalized = [];

        foreach ($abilities as $ability) {
            $ability = trim($ability);

            if ($ability !== '') {
                $normalized[] = $ability;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $abilities
     */
    private function auditIssueDenied(
        User $issuer,
        TenantContext $context,
        Project $project,
        array $abilities,
        string $reason,
        Request $request,
    ): void {
        $this->auditEvents->append([
            'event_type' => 'token.grant',
            'action' => 'create',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $issuer->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'token_grant',
            'resource_id' => null,
            'resource_tenant' => $context->activeTenantId,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => $abilities,
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'track' => 'pat',
                'reason' => $reason,
                'resource_type' => $project->resourceType(),
                'resource_id' => $project->resourceId(),
            ],
        ]);
    }
}
