<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Tests;

use Testo\Assert;
use Thesis\Async\Trace\Event;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Subscriber;

/**
 * The events of the `@phpstan-sealed` list of `Event` that the dispatcher does not give to the subscriber.
 * PHPStan keeps that list complete: a class not in it cannot implement `Event`.
 *
 * @return list<string>
 */
function unheardEvents(Subscriber $subscriber): array
{
    $dispatcher = new Dispatcher([$subscriber]);
    $doc = new \ReflectionClass(Event::class)->getDocComment();

    if ($doc === false || preg_match('/@phpstan-sealed\h+(\S+)/', $doc, $matches) !== 1) {
        throw new \LogicException('Event has no @phpstan-sealed list.');
    }

    $unheard = [];

    foreach (explode('|', $matches[1]) as $name) {
        $class = 'Thesis\Async\Trace\\' . $name;

        if (!class_exists($class) || !$dispatcher->listens($class)) {
            $unheard[] = $class;
        }
    }

    return $unheard;
}

/**
 * What $fn throws, for a program whose failure must not end the observation around it.
 *
 * @param callable(): mixed $fn
 */
function thrown(callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $exception) {
        return $exception;
    }

    Assert::fail('Nothing was thrown.');
}
