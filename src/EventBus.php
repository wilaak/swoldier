<?php

declare(strict_types=1);

namespace Swoldier;

/**
 * Simple event-bus implementation
 */
class EventBus
{
    private array $listeners = [];
    private array $wildcardListeners = [];

    /**
     * Emit an event.
     */
    public function emit(string $event, mixed $payload = null): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }

        foreach ($this->wildcardListeners as $pattern => $listeners) {
            $prefix = \rtrim($pattern, '*');
            if ($pattern === '*' || ($prefix !== '' && \str_starts_with($event, $prefix))) {
                foreach ($listeners as $listener) {
                    $listener($event, $payload);
                }
            }
        }
    }

    /**
     * Subscribe to an event or wildcard.
     *
     * @return callable Unsubscribe function
     */
    public function on(string $event, callable $listener): callable
    {
        if (\str_contains($event, '*')) {
            $this->wildcardListeners[$event][] = $listener;
            $index = \array_key_last($this->wildcardListeners[$event]);
            return function () use ($event, $index) {
                if (isset($this->wildcardListeners[$event][$index])) {
                    unset($this->wildcardListeners[$event][$index]);
                }
            };
        }

        $this->listeners[$event][] = $listener;
        $index = \array_key_last($this->listeners[$event]);
        return function () use ($event, $index) {
            if (isset($this->listeners[$event][$index])) {
                unset($this->listeners[$event][$index]);
            }
        };
    }

    /**
     * Run once for an event or wildcard (e.g. 'user.*' or '*')
     */
    public function once(string $event, callable $listener): void
    {
        $unsubscribe = null;
        $unsubscribe = $this->on($event, function ($payloadOrEvent, $payload = null) use ($listener, &$unsubscribe, $event) {
            if (\str_contains($event, '*')) {
                $listener($payloadOrEvent, $payload);
            } else {
                $listener($payloadOrEvent);
            }
            if ($unsubscribe) {
                $unsubscribe();
            }
        });
    }
}
