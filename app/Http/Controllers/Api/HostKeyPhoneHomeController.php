<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHostKeyPhoneHomeRequest;
use App\Models\DeploymentHostKey;
use App\Models\HostKeyPhoneHomeToken;
use App\Provisioning\ProvisioningPayload;
use App\Provisioning\SshPublicKeyFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HostKeyPhoneHomeController extends Controller
{
    public function __invoke(
        StoreHostKeyPhoneHomeRequest $request,
        string $token,
        SshPublicKeyFingerprint $fingerprints,
        AuditEventWriter $auditEvents,
    ): JsonResponse {
        $record = $this->tokenRecord($token);

        if (! $record instanceof HostKeyPhoneHomeToken || $record->used_at !== null || $record->expires_at->isPast()) {
            if ($record instanceof HostKeyPhoneHomeToken) {
                $this->audit($auditEvents, $request, $record, 'denied', ['reason' => 'token_unavailable']);
            }

            throw new NotFoundHttpException('Phone-home token not found.');
        }

        $keys = $this->parsedKeys($request->input('keys'), $fingerprints);

        $created = DB::transaction(function () use ($record, $keys, $auditEvents, $request): array {
            $created = [];

            foreach ($keys as $details) {
                /** @var DeploymentHostKey $hostKey */
                $hostKey = DeploymentHostKey::query()->firstOrCreate(
                    [
                        'tenant_id' => $record->tenant_id,
                        'deployment_id' => $record->deployment_id,
                        'deployment_resource_id' => $record->deployment_resource_id,
                        'fingerprint' => $details->fingerprint,
                    ],
                    [
                        'key_type' => $details->keyType,
                        'public_key' => $details->publicKey,
                        'first_seen_at' => now(),
                        'metadata' => [],
                    ],
                );
                $created[] = $hostKey;
            }

            $record->forceFill(['used_at' => now()])->save();
            $this->audit($auditEvents, $request, $record, 'allowed', ['keys_recorded' => count($created)]);

            return $created;
        });

        return response()->json([
            'data' => [
                'deployment_id' => $record->deployment_id,
                'keys_recorded' => count($created),
                'keys' => array_map(
                    ProvisioningPayload::deploymentHostKey(...),
                    $created,
                ),
            ],
        ]);
    }

    private function tokenRecord(string $plainToken): ?HostKeyPhoneHomeToken
    {
        /** @var HostKeyPhoneHomeToken|null $record */
        $record = HostKeyPhoneHomeToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        return $record;
    }

    /**
     * @return list<\App\Provisioning\SshPublicKeyDetails>
     */
    private function parsedKeys(mixed $rawKeys, SshPublicKeyFingerprint $fingerprints): array
    {
        if (! is_array($rawKeys)) {
            return [];
        }

        $parsed = [];

        foreach ($rawKeys as $index => $rawKey) {
            if (! is_array($rawKey)) {
                continue;
            }

            if (! isset($rawKey['public_key'])) {
                continue;
            }

            if (! is_string($rawKey['public_key'])) {
                continue;
            }

            try {
                $parsed[] = $fingerprints->parse($rawKey['public_key']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    sprintf('keys.%s.public_key', (string) $index) => $exception->getMessage(),
                ]);
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        StoreHostKeyPhoneHomeRequest $request,
        HostKeyPhoneHomeToken $token,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'host_key.phone_home',
            'action' => 'capture',
            'result' => $result,
            'actor_type' => 'system',
            'actor_id' => 'cloud-init',
            'actor_tenant' => $token->tenant_id,
            'resource_type' => 'deployment',
            'resource_id' => $token->deployment_id,
            'resource_tenant' => $token->tenant_id,
            'target_tenant_set' => [$token->tenant_id],
            'effective_permissions' => [],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                ...$metadata,
                'host_key_phone_home_token_id' => $token->getKey(),
                'deployment_resource_id' => $token->deployment_resource_id,
            ],
        ]);
    }
}
