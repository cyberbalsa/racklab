<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishes a project-local StackDefinition to the tenant catalog as an
 * immutable published CatalogVersion (PRD §08). Authorized by `catalog.publish`
 * on the source stack's project through AccessResolver (instructors/admins hold
 * it). The published version wraps a catalog-scoped *copy* of the source
 * definition, so later edits to the project-local stack never mutate a
 * published version. Every publish is audited.
 */
final readonly class CatalogPublisher
{
    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
    ) {}

    public function publish(
        User $actor,
        TenantContext $context,
        StackDefinition $source,
        string $itemName,
        string $versionLabel,
        ?string $description,
    ): CatalogVersion {
        if ($source->project_id === null) {
            throw new NotFoundHttpException('Only project-local stacks can be published.');
        }

        /** @var Project|null $project */
        $project = Project::query()->whereKey($source->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $decision = $this->accessResolver->permitted(
            new ActorIdentity((string) $actor->id),
            new Permission('catalog.publish'),
            $project,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to publish to the catalog.');
        }

        $tenantId = $context->activeTenantId;

        $version = DB::transaction(function () use ($source, $itemName, $versionLabel, $description, $tenantId): CatalogVersion {
            /** @var CatalogItem $item */
            $item = CatalogItem::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => Str::slug($itemName)],
                [
                    'name' => $itemName,
                    'description' => $description,
                    'sharing_scope' => 'tenant_local',
                    'shared_with_tenants' => [],
                ],
            );

            // Catalog versions are immutable: republishing the same label for an
            // existing item is a user error, not a DB-level 500.
            if (CatalogVersion::query()
                ->where('catalog_item_id', $item->getKey())
                ->where('version', $versionLabel)
                ->exists()) {
                throw new DuplicateCatalogVersionException(
                    sprintf('Version "%s" already exists for this catalog item.', $versionLabel),
                );
            }

            /** @var StackDefinition $catalogStack */
            $catalogStack = StackDefinition::query()->create([
                'tenant_id' => $tenantId,
                'project_id' => null,
                'name' => $itemName,
                'slug' => $this->uniqueStackSlug($tenantId, Str::slug($itemName)),
                'scope' => 'catalog',
                'is_reserved_default' => false,
                'definition' => $source->definition ?? [],
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
            ]);

            /** @var CatalogVersion $version */
            $version = CatalogVersion::query()->create([
                'tenant_id' => $tenantId,
                'catalog_item_id' => $item->getKey(),
                'stack_definition_id' => $catalogStack->getKey(),
                'version' => $versionLabel,
                'state' => 'published',
                'published_at' => Carbon::now(),
                'summary' => $description,
            ]);

            return $version;
        });

        $this->auditEvents->append([
            'event_type' => 'catalog.publish',
            'action' => 'publish',
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $tenantId,
            'resource_type' => 'catalog_item',
            'resource_id' => $version->catalog_item_id,
            'resource_tenant' => $tenantId,
            'target_tenant_set' => [$tenantId],
            'effective_permissions' => ['catalog.publish'],
            'metadata' => [
                'catalog_version_id' => $version->getKey(),
                'version' => $versionLabel,
                'source_stack_id' => $source->getKey(),
            ],
        ]);

        return $version;
    }

    private function uniqueStackSlug(string $tenantId, string $base): string
    {
        if ($base === '') {
            $base = 'catalog-stack';
        }

        $candidate = $base;
        $suffix = 1;

        while (StackDefinition::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('project_id')
            ->where('slug', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }
}
