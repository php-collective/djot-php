<?php

declare(strict_types=1);

namespace Djot\Renderer\Utility;

use Closure;
use Djot\Event\RenderEvent;

/**
 * Trait for event dispatching in renderers.
 *
 * Provides consistent event listener registration and dispatching
 * across all renderer implementations.
 */
trait EventDispatcherTrait
{
    /**
     * Event listeners
     *
     * @var array<string, array<\Closure(\Djot\Event\RenderEvent): void>>
     */
    protected array $listeners = [];

    /**
     * Register an event listener
     *
     * Event names correspond to node types:
     * - render.link, render.image, render.paragraph, etc.
     * - render.* for all nodes
     *
     * @param string $event Event name (e.g., 'render.paragraph', 'render.*')
     * @param \Closure(\Djot\Event\RenderEvent): void $listener
     */
    public function on(string $event, Closure $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Remove event listeners
     *
     * @param string|null $event Event name or null to remove all listeners
     */
    public function off(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }

    /**
     * Check if there are any listeners registered for any render event
     */
    protected function hasAnyListeners(): bool
    {
        return $this->listeners !== [];
    }

    /**
     * Dispatch an event to all registered listeners
     *
     * Calls each listener for the given event in registration order.
     * Stops if a listener calls preventDefault() on the event.
     */
    protected function dispatchEvent(string $event, RenderEvent $renderEvent): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }
        foreach ($this->listeners[$event] as $listener) {
            $listener($renderEvent);
            if ($renderEvent->isDefaultPrevented()) {
                break;
            }
        }
    }

    /**
     * Dispatch render events for a node
     *
     * Dispatches both the specific event (e.g., render.paragraph) and
     * the wildcard event (render.*).
     *
     * @param string $nodeType The node type for the specific event
     * @param \Djot\Event\RenderEvent $event The render event
     */
    protected function dispatchRenderEvents(string $nodeType, RenderEvent $event): void
    {
        $eventName = 'render.' . $nodeType;
        $this->dispatchEvent($eventName, $event);
        $this->dispatchEvent('render.*', $event);
    }
}
