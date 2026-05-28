<?php

declare(strict_types=1);

namespace App\Plugins;

use Closure;
use InvalidArgumentException;

final class HookDispatcher
{
    /**
     * @var array<class-string, list<HookListenerRegistration>>
     */
    private array $listeners = [];

    /**
     * @param  class-string  $eventClass
     * @param  callable(object): mixed  $listener
     */
    public function listen(
        string $eventClass,
        callable $listener,
        HookListenerStyle $style,
        string $pluginSlug,
        int $priority = 1000,
    ): void {
        if (! class_exists($eventClass)) {
            throw new InvalidArgumentException(sprintf('Hookspec event [%s] is not autoloadable.', $eventClass));
        }

        $this->listeners[$eventClass][] = new HookListenerRegistration(
            eventClass: $eventClass,
            listener: Closure::fromCallable($listener),
            style: $style,
            pluginSlug: $pluginSlug,
            priority: $priority,
        );
    }

    /**
     * @template TEvent of object
     *
     * @param  TEvent  $event
     * @return TEvent
     */
    public function filter(object $event): object
    {
        $current = $event;

        foreach ($this->listenersFor($event, HookListenerStyle::Filter) as $registration) {
            $result = ($registration->listener)($current);

            if ($result === null) {
                continue;
            }

            $eventClass = $current::class;

            if (! is_object($result) || ! $result instanceof $eventClass) {
                throw new InvalidArgumentException('Filter hooks must return the same hookspec event type or null.');
            }

            $current = $result;
        }

        return $current;
    }

    /**
     * @return list<mixed>
     */
    public function contribute(object $event): array
    {
        $contributions = [];

        foreach ($this->listenersFor($event, HookListenerStyle::Contributor) as $registration) {
            $result = ($registration->listener)($event);

            if ($result === null) {
                continue;
            }

            if (is_iterable($result)) {
                foreach ($result as $entry) {
                    $contributions[] = $entry;
                }

                continue;
            }

            $contributions[] = $result;
        }

        return $contributions;
    }

    public function resolve(object $event): mixed
    {
        foreach ($this->listenersFor($event, HookListenerStyle::Resolver) as $registration) {
            $result = ($registration->listener)($event);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function notify(object $event): void
    {
        foreach ($this->listenersFor($event, HookListenerStyle::Notification) as $registration) {
            ($registration->listener)($event);
        }
    }

    /**
     * @return list<HookListenerRegistration>
     */
    private function listenersFor(object $event, HookListenerStyle $style): array
    {
        $eventClass = $event::class;
        $listeners = array_values(array_filter(
            $this->listeners[$eventClass] ?? [],
            static fn (HookListenerRegistration $registration): bool => $registration->style === $style,
        ));

        usort(
            $listeners,
            static fn (HookListenerRegistration $a, HookListenerRegistration $b): int => [$a->priority, $a->pluginSlug]
                <=> [$b->priority, $b->pluginSlug],
        );

        return $listeners;
    }
}
