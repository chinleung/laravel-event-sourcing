<?php

namespace Spatie\EventSourcing\EventHandlers;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Illuminate\Support\Collection;
use Spatie\EventSourcing\StoredEvent;
use Spatie\EventSourcing\ShouldBeStored;
use Spatie\EventSourcing\Exceptions\InvalidEventHandler;

trait HandlesEvents
{
    public function handles(): array
    {
        return $this->getEventHandlingMethods()->keys()->toArray();
    }

    public function handle(StoredEvent $storedEvent)
    {
        $eventClass = $storedEvent->event_class;

        $handlerClassOrMethod = $this->getEventHandlingMethods()->get($eventClass);

        $parameters = [
            'event' => $storedEvent->event,
            'storedEvent' => $storedEvent,
            'aggregateUuid' => $storedEvent->aggregate_uuid,
        ];

        if (class_exists($handlerClassOrMethod)) {
            return app()->call([app($handlerClassOrMethod), '__invoke'], $parameters);
        }

        if (! method_exists($this, $handlerClassOrMethod)) {
            throw InvalidEventHandler::eventHandlingMethodDoesNotExist($this, $storedEvent->event, $handlerClassOrMethod);
        }

        app()->call([$this, $handlerClassOrMethod], $parameters);
    }

    public function handleException(Exception $exception): void
    {
        report($exception);
    }

    public function getEventHandlingMethods(): Collection
    {
        if (! isset($this->handlesEvents) && ! isset($this->handleEvent)) {
            return $this->autoDetectHandlesEvents();
        }

        $handlesEvents = collect($this->handlesEvents ?? [])
            ->mapWithKeys(function (string $handlerMethod, $eventClass) {
                if (is_numeric($eventClass)) {
                    return [$handlerMethod => 'on'.ucfirst(class_basename($handlerMethod))];
                }

                return [$eventClass => $handlerMethod];
            });

        if ($this->handleEvent ?? false) {
            $handlesEvents->put($this->handleEvent, get_class($this));
        }

        return $handlesEvents;
    }

    private function autoDetectHandlesEvents(): Collection
    {
        return collect((new ReflectionClass($this))->getMethods())
            ->flatMap(function (ReflectionMethod $method) {
                $method = new ReflectionMethod($this, $method->name);
                if (! $method->isPublic()) {
                    return;
                }

                $eventClass = collect($method->getParameters())
                    ->map(function (ReflectionParameter $parameter) {
                        return optional($parameter->getType())->getName();
                    })
                    ->first(function ($typeHint) {
                        return is_subclass_of($typeHint, ShouldBeStored::class);
                    });

                if (! $eventClass) {
                    return;
                }

                return [$eventClass => $method->name];
            })
            ->filter();
    }
}
