<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Internal;

use Thesis\Dispatcher;
use Thesis\Dispatcher\Subscriber;
use function Thesis\Formatter\formatReflectedFunction;
use function Thesis\Formatter\formatReflectedParameter;

/**
 * A listener of `Thesis\Dispatcher` with the type of its parameter in the disjunctive normal form:
 * `(A&B)|C` is `[[A, B], [C]]`, so a plain class, a union and an intersection are matched by the same loop.
 *
 * @internal
 */
final readonly class Listener
{
    /**
     * The dispatcher uses the subscriber's listeners in order. It does not call the subscriber itself,
     * even if the subscriber is invokable.
     *
     * @param iterable<(callable(never, Dispatcher): void)|Subscriber> $listeners
     * @return list<self>
     * @throws \InvalidArgumentException
     */
    public static function all(iterable $listeners): array
    {
        $all = [];

        foreach ($listeners as $listener) {
            $callables = $listener instanceof Subscriber ? $listener->listeners() : [$listener];

            foreach ($callables as $callable) {
                $all[] = self::of($callable);
            }
        }

        return $all;
    }

    /**
     * @param callable(never, Dispatcher): void $listener
     */
    private static function of(callable $listener): self
    {
        /** @var \Closure(object, Dispatcher): void $fn the parameter of the event is checked below */
        $fn = \Closure::fromCallable($listener);
        $function = new \ReflectionFunction($fn);
        $parameters = $function->getParameters();

        if ($function->isGenerator()) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must not be a generator: its body would not run.',
                formatReflectedFunction($function),
            ));
        }

        if ($parameters === []) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must take the event as its first parameter.',
                formatReflectedFunction($function),
            ));
        }

        $event = $parameters[0];

        if ($event->isVariadic()) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must take the event as a plain parameter, not a variadic one.',
                formatReflectedParameter($event),
            ));
        }

        if ($event->isPassedByReference()) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must take the event by value, not by reference.',
                formatReflectedParameter($event),
            ));
        }

        return new self($fn, self::types($event));
    }

    /**
     * @param \Closure(object, Dispatcher): void $fn
     * @param ?list<list<class-string>> $types null for every event
     */
    private function __construct(
        public \Closure $fn,
        private ?array $types,
    ) {}

    /**
     * @param class-string $class
     */
    public function accepts(string $class): bool
    {
        if ($this->types === null) {
            return true;
        }

        return array_any($this->types, static fn($intersection) => self::isAll($class, $intersection));
    }

    /**
     * @param class-string $class
     * @param list<class-string> $types
     */
    private static function isAll(string $class, array $types): bool
    {
        return array_all($types, static fn($type) => is_a($class, $type, allow_string: true));
    }

    /**
     * @return ?list<list<class-string>>
     */
    private static function types(\ReflectionParameter $event): ?array
    {
        $type = $event->getType();

        if ($type === null) {
            return null;
        }

        if ($type instanceof \ReflectionNamedType && self::isEveryEvent($type)) {
            return null;
        }

        $intersections = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        $types = [];

        foreach ($intersections as $intersection) {
            $named = $intersection instanceof \ReflectionIntersectionType ? $intersection->getTypes() : [$intersection];
            $classes = [];

            foreach ($named as $class) {
                $classes[] = self::class($event, $class);
            }

            $types[] = $classes;
        }

        return $types;
    }

    private static function isEveryEvent(\ReflectionNamedType $type): bool
    {
        // mixed allows null by itself, ?object is rejected as a nullable type in class().
        return $type->getName() === 'mixed' || ($type->getName() === 'object' && !$type->allowsNull());
    }

    /**
     * @return class-string
     */
    private static function class(\ReflectionParameter $event, \ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be typed with classes, interfaces or object, got %s.',
                formatReflectedParameter($event),
                (string) $event->getType(),
            ));
        }

        $scope = $event->getDeclaringClass()?->name;
        $name = match ($type->getName()) {
            'self' => $scope,
            'parent' => $scope === null ? null : get_parent_class($scope),
            default => $type->getName(),
        };

        if (!\is_string($name) || !(class_exists($name) || interface_exists($name))) {
            throw new \InvalidArgumentException(\sprintf(
                '%s is typed with %s, which is not a class or an interface.',
                formatReflectedParameter($event),
                $type->getName(),
            ));
        }

        return $name;
    }
}
