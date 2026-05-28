<?php

declare(strict_types=1);

use App\Events\Hookspecs\Deployment\CreatingEvent;
use App\Plugins\HookDispatcher;
use App\Plugins\HookListenerStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs filter listeners in deterministic priority order', function (): void {
    $dispatcher = new HookDispatcher;

    $dispatcher->listen(
        CreatingEvent::class,
        static fn (CreatingEvent $event): CreatingEvent => $event->withMetadata(['first' => true]),
        HookListenerStyle::Filter,
        pluginSlug: 'racklab/plugin-b',
        priority: 20,
    );
    $dispatcher->listen(
        CreatingEvent::class,
        static fn (CreatingEvent $event): CreatingEvent => $event->withMetadata(['second' => $event->metadata['first'] ?? false]),
        HookListenerStyle::Filter,
        pluginSlug: 'racklab/plugin-a',
        priority: 30,
    );

    $result = $dispatcher->filter(new CreatingEvent(
        tenantId: 'tenant-1',
        projectId: 'project-1',
        stackDefinitionId: 'stack-1',
        metadata: [],
    ));

    expect($result->metadata)->toBe(['first' => true, 'second' => true]);
});

it('aggregates contributor listeners and short-circuits resolvers', function (): void {
    $dispatcher = new HookDispatcher;

    $dispatcher->listen(CreatingEvent::class, static fn (): array => ['alpha'], HookListenerStyle::Contributor, 'racklab/plugin-b', 20);
    $dispatcher->listen(CreatingEvent::class, static fn (): array => ['beta'], HookListenerStyle::Contributor, 'racklab/plugin-a', 10);
    $dispatcher->listen(CreatingEvent::class, static fn (): null => null, HookListenerStyle::Resolver, 'racklab/plugin-a', 10);
    $dispatcher->listen(CreatingEvent::class, static fn (): string => 'winner', HookListenerStyle::Resolver, 'racklab/plugin-b', 20);
    $dispatcher->listen(CreatingEvent::class, static fn (): string => 'ignored', HookListenerStyle::Resolver, 'racklab/plugin-c', 30);

    $event = new CreatingEvent('tenant-1', 'project-1', 'stack-1', []);

    expect($dispatcher->contribute($event))->toBe(['beta', 'alpha'])
        ->and($dispatcher->resolve($event))->toBe('winner');
});

it('runs notification listeners without mutating the event', function (): void {
    $dispatcher = new HookDispatcher;
    $seen = [];

    $dispatcher->listen(
        CreatingEvent::class,
        function (CreatingEvent $event) use (&$seen): void {
            $seen[] = $event->stackDefinitionId;
        },
        HookListenerStyle::Notification,
        pluginSlug: 'racklab/plugin-hello',
    );

    $event = new CreatingEvent('tenant-1', 'project-1', 'stack-1', []);

    $dispatcher->notify($event);

    expect($seen)->toBe(['stack-1'])
        ->and($event->metadata)->toBe([]);
});
