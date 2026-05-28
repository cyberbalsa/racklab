<?php

declare(strict_types=1);

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\RefResolverRegistry;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Events\Hookspecs\Docs\RefResolvingEvent;
use App\Plugins\HookDispatcher;
use App\Plugins\HookListenerStyle;

function fakeResolver(string $kind): RefResolver
{
    return new readonly class($kind) implements RefResolver
    {
        public function __construct(private string $kind) {}

        public function kind(): string
        {
            return $this->kind;
        }

        public function resolve(RefResolutionContext $context, string $id): ResolvedRef
        {
            return ResolvedRef::resolved($this->kind, $id, $this->kind.' label', '/'.$this->kind.'/'.$id, null);
        }
    };
}

it('returns a built-in core resolver for a core kind', function (): void {
    $core = fakeResolver('deployment');
    $registry = new RefResolverRegistry(new HookDispatcher, [$core]);

    expect($registry->resolverFor('deployment'))->toBe($core);
});

it('falls through to a plugin-contributed resolver via the RefResolving hookspec', function (): void {
    $dispatcher = new HookDispatcher;
    $clusterResolver = fakeResolver('cluster');
    $dispatcher->listen(
        RefResolvingEvent::class,
        static fn (RefResolvingEvent $event): ?RefResolver => $event->kind === 'cluster' ? $clusterResolver : null,
        HookListenerStyle::Resolver,
        'racklab/provider-proxmox',
        1000,
    );

    $registry = new RefResolverRegistry($dispatcher, []);

    expect($registry->resolverFor('cluster'))->toBe($clusterResolver)
        ->and($registry->resolverFor('unknownkind'))->toBeNull();
});

it('prefers a core resolver over a plugin resolver for the same kind', function (): void {
    $dispatcher = new HookDispatcher;
    $pluginDeployment = fakeResolver('deployment');
    $dispatcher->listen(
        RefResolvingEvent::class,
        static fn (RefResolvingEvent $event): ?RefResolver => $event->kind === 'deployment' ? $pluginDeployment : null,
        HookListenerStyle::Resolver,
        'racklab/rogue-plugin',
        1,
    );

    $core = fakeResolver('deployment');
    $registry = new RefResolverRegistry($dispatcher, [$core]);

    // Core wins — a plugin cannot hijack resolution of a first-class core kind.
    expect($registry->resolverFor('deployment'))->toBe($core);
});

it('ignores a hookspec listener that returns a non-resolver value', function (): void {
    $dispatcher = new HookDispatcher;
    $dispatcher->listen(
        RefResolvingEvent::class,
        static fn (RefResolvingEvent $event): string => 'not-a-resolver',
        HookListenerStyle::Resolver,
        'racklab/broken-plugin',
        1000,
    );

    $registry = new RefResolverRegistry($dispatcher, []);

    expect($registry->resolverFor('deployment'))->toBeNull();
});

it('constructs a resolution context from actor and tenant', function (): void {
    $context = new RefResolutionContext(
        new ActorIdentity('user-1'),
        new TenantContext('tenant-1'),
    );

    expect($context->actor->id)->toBe('user-1')
        ->and($context->tenant->activeTenantId)->toBe('tenant-1');
});
