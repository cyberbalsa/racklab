<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\BroadcastEventLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReplayController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if (! $tokenAbilities->allows($request, 'deployment.read')) {
            throw new AuthorizationException('The current token does not include deployment.read.');
        }

        $channel = $request->string('channel')->toString();
        $since = $request->string('since')->toString();
        $deployment = $this->deploymentFromChannel($channel, $context);

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.read'),
            $deployment,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to replay this channel.');
        }

        $retentionFloor = now()->subHours(24);

        /** @var BroadcastEventLog|null $cursor */
        $cursor = BroadcastEventLog::query()
            ->where('channel', $channel)
            ->whereKey($since)
            ->first();

        if ($cursor instanceof BroadcastEventLog && $cursor->created_at->lessThan($retentionFloor)) {
            return response()->json(['gap' => true, 'events' => []]);
        }

        $events = BroadcastEventLog::query()
            ->where('channel', $channel)
            ->where('id', '>', $since)
            ->where('created_at', '>=', $retentionFloor)
            ->orderBy('id')
            ->limit(1000)
            ->get()
            ->map(static fn (BroadcastEventLog $event): array => [
                'id' => $event->getKey(),
                'channel' => $event->channel,
                'event_class' => $event->event_class,
                'payload' => $event->payload,
                'created_at' => $event->created_at->toJSON(),
            ])
            ->all();

        return response()->json(['gap' => false, 'events' => $events]);
    }

    private function deploymentFromChannel(string $channel, TenantContext $context): Deployment
    {
        $parts = explode('.', $channel);

        if (
            count($parts) !== 4
            || $parts[0] !== 'private-tenant'
            || $parts[1] !== $context->activeTenantId
            || $parts[2] !== 'deployment'
        ) {
            throw new AuthorizationException('Replay channel is not authorized.');
        }

        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()->whereKey($parts[3])->first();

        if (! $deployment instanceof Deployment) {
            throw new AuthorizationException('Replay channel is not authorized.');
        }

        return $deployment;
    }
}
