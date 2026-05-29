<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Deployments\DefaultStackResolver;
use App\Deployments\DeploymentCreateResult;
use App\Deployments\FakeDeploymentLifecycle;
use App\Deployments\ProxmoxDeploymentLifecycle;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\CourseMembership;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared deploy-from-stack path used by both the JSON deployment API and the
 * browser catalog page. Enforces `catalog.read` on the source catalog version
 * (when one is supplied) and routes to the provider-specific lifecycle, which
 * in turn enforces `deployment.create` on the target project. Keeping this in
 * one place stops the API and UI deploy paths from diverging.
 */
final readonly class CatalogDeployer
{
    public function __construct(
        private DefaultStackResolver $stacks,
        private FakeDeploymentLifecycle $fakeDeployments,
        private ProxmoxDeploymentLifecycle $proxmoxDeployments,
        private AccessResolver $accessResolver,
    ) {}

    public function deploy(
        User $user,
        TenantContext $context,
        Project $project,
        Request $request,
        string $operationKind,
        string $idempotencyKey,
        string $catalogVersionId = '',
        string $stackDefinitionId = '',
        bool $simulateFailure = false,
        string $courseId = '',
    ): DeploymentCreateResult {
        $this->authorizeCatalogVersionRead($catalogVersionId, $user, $context);
        $this->authorizeCourseAssociation($courseId, $user, $context);

        $stack = $this->stacks->forProjectOrCatalogVersion(
            project: $project,
            stackDefinitionId: $stackDefinitionId,
            catalogVersionId: $catalogVersionId,
        );

        if ($this->stackProvider($stack) === 'proxmox') {
            $result = $this->proxmoxDeployments->request(
                actor: $user,
                context: $context,
                project: $project,
                stack: $stack,
                operationKind: $operationKind,
                idempotencyKey: $idempotencyKey,
                request: $request,
            );
        } else {
            $result = $this->fakeDeployments->request(
                actor: $user,
                context: $context,
                project: $project,
                stack: $stack,
                operationKind: $operationKind,
                idempotencyKey: $idempotencyKey,
                request: $request,
                simulateFailure: $simulateFailure,
            );
        }

        // Tag the deployment with its course so course staff gain managed
        // access (only after membership was validated above). Skip on
        // idempotent replays so we never re-stamp a prior deployment.
        if ($courseId !== '' && ! $result->idempotentReplay && $result->deployment->course_id === null) {
            $result->deployment->forceFill(['course_id' => $courseId])->save();
        }

        return $result;
    }

    /**
     * A deployment may only be associated with a course the actor actually
     * belongs to — prevents tagging a deployment into an arbitrary course (which
     * would otherwise expose it to that course's staff).
     */
    private function authorizeCourseAssociation(string $courseId, User $user, TenantContext $context): void
    {
        if ($courseId === '') {
            return;
        }

        $isMember = CourseMembership::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('You are not a member of that course.');
        }
    }

    private function authorizeCatalogVersionRead(
        string $catalogVersionId,
        User $user,
        TenantContext $context,
    ): void {
        if ($catalogVersionId === '') {
            return;
        }

        /** @var CatalogVersion|null $version */
        $version = CatalogVersion::query()
            ->whereKey($catalogVersionId)
            ->where('state', 'published')
            ->first();

        if (! $version instanceof CatalogVersion) {
            throw new NotFoundHttpException('Catalog version not found.');
        }

        /** @var CatalogItem|null $item */
        $item = CatalogItem::query()->whereKey($version->catalog_item_id)->first();

        if (! $item instanceof CatalogItem) {
            throw new NotFoundHttpException('Catalog version not found.');
        }

        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('catalog.read'),
            $item,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Catalog version not found.');
        }
    }

    private function stackProvider(StackDefinition $stack): string
    {
        $definition = $stack->definition ?? [];
        $provider = $definition['provider'] ?? null;

        return is_string($provider) ? $provider : 'fake';
    }
}
