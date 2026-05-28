<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\TrackBTokenService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTokenGrantRequest;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TokenStoreController extends Controller
{
    public function __invoke(
        StoreTokenGrantRequest $request,
        TenantContextStore $tenantContext,
        TrackBTokenService $tokens,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $projectId = $request->string('project_id')->toString();

        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $rawAbilities = $request->input('abilities');

        if (! is_array($rawAbilities)) {
            throw ValidationException::withMessages(['abilities' => 'Token abilities must be an array.']);
        }

        $abilities = [];

        foreach ($rawAbilities as $ability) {
            if (is_string($ability)) {
                $abilities[] = $ability;
            }
        }

        $rawExpiresAt = $request->input('expires_at');
        $expiresAt = is_string($rawExpiresAt)
            ? CarbonImmutable::parse($rawExpiresAt)
            : null;

        $issue = $tokens->issue(
            issuer: $user,
            context: $context,
            project: $project,
            name: $request->string('name')->toString(),
            abilities: $abilities,
            expiresAt: $expiresAt,
            request: $request,
        );

        $data = TokenIndexController::serializeGrant($issue->grant, includeSecret: true);
        $data['plain_text_token'] = $issue->plainTextToken;
        $data['authorization_header'] = 'Token '.$issue->plainTextToken;

        return response()->json(['data' => $data], 201);
    }
}
