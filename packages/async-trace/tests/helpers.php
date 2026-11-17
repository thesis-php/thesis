<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Tests;

use Thesis\Async\Trace\Event;
use Thesis\Dispatcher;
use function Thesis\Dispatcher\listenersOf;

/**
 * The events of the `@phpstan-sealed` list of `Event` that the dispatcher does not give to the listener.
 * PHPStan keeps that list complete: a class not in it cannot implement `Event`.
 *
 * @param object $subscriber with methods marked `#[Listener]`
 * @return list<string>
 */
function unheardEvents(object $subscriber): array
{
    $dispatcher = new Dispatcher(listenersOf($subscriber));
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
