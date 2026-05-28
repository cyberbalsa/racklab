<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Tokens\TrackBTokenService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountTokenStoreController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        TrackBTokenService $tokens,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'project_id' => ['required', 'string'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in($this->supportedAbilities())],
        ]);

        /** @var Project|null $project */
        $project = Project::query()->whereKey($request->string('project_id')->toString())->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $issue = $tokens->issue(
            issuer: $user,
            context: $context,
            project: $project,
            name: $request->string('name')->toString(),
            abilities: $this->abilities($request->input('abilities')),
            expiresAt: null,
            request: $request,
        );

        return redirect()
            ->route('dashboard')
            ->with('issued_token_name', $issue->grant->name)
            ->with('issued_token_authorization_header', 'Token '.$issue->plainTextToken);
    }

    /**
     * @return list<string>
     */
    private function supportedAbilities(): array
    {
        return [
            'project.read',
            'deployment.read',
            'deployment.create',
        ];
    }

    /**
     * @return list<string>
     */
    private function abilities(mixed $rawAbilities): array
    {
        if (! is_array($rawAbilities)) {
            return [];
        }

        $abilities = [];

        foreach ($rawAbilities as $ability) {
            if (is_string($ability)) {
                $abilities[] = $ability;
            }
        }

        return $abilities;
    }
}
