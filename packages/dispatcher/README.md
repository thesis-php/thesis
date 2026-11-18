# Thesis Dispatcher

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/dispatcher/php)](https://packagist.org/packages/thesis/dispatcher)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/dispatcher)](https://github.com/thesis-php/dispatcher/releases)

Synchronous event dispatcher: a listener gets the events of the type of its parameter, resolved once per class.

```php
use Thesis\Dispatcher;

$dispatcher = new Dispatcher([
    static function (UserRegistered $event) { /* ... */ },
    static function (OrderPlaced|OrderCancelled $event) { /* ... */ },
    new AuditLog(), // __invoke(DomainEvent $event)
    new Mailer(), // a Subscriber: several listeners of one object
]);

$dispatcher->dispatch(new UserRegistered('user@example.com'));
```

## Installation

```shell
composer require thesis/dispatcher
```

Requires PHP 8.6+. No extensions.

## Listeners

A listener is any callable: a closure, an invokable object, `[$object, 'method']`, `$object->method(...)`.
It gets the event as its first parameter, and the type of that parameter says which events it gets:

| Type | Events |
|---|---|
| `UserRegistered` | of the class and its subclasses |
| `DomainEvent` (an interface) | of every class that implements it |
| `OrderPlaced\|OrderCancelled` | of any of them, the listener is called once for an event of both |
| `DomainEvent&Auditable` | of the classes that are both |
| `(DomainEvent&Auditable)\|AuditRequested` | any of the above, combined |
| `object`, `mixed`, no type | every event |

`self` and `parent` name the class of the method. The constructor checks every listener and throws
`\InvalidArgumentException` if it cannot take an event: no parameter, a nullable type (the dispatcher never passes
`null`), a scalar, a class or an interface that does not exist, a variadic or a reference parameter,
a generator (its body would not run).

The second argument of a listener is the dispatcher itself, for a listener that dispatches events of its own.
A listener that does not need it declares the event alone:

```php
$dispatcher = new Dispatcher([
    static function (OrderPlaced $event, Dispatcher $dispatcher) {
        if ($event->total > 1000) {
            $dispatcher->dispatch(new LargeOrderPlaced($event));
        }
    },
    static function (LargeOrderPlaced $event) { /* ... */ },
]);
```

The parameters after the event are checked by PHPStan, not by the constructor: the second one takes
`Thesis\Dispatcher`, the ones after it are optional. Only a function of PHP itself that declares one parameter
(`$queue->push(...)`) cannot be a listener: unlike a function written in PHP, it rejects an argument
it does not declare.

### Subscribers

An object with several listeners implements `Subscriber` and is given to the dispatcher as it is,
among any other listeners:

```php
use Thesis\Dispatcher\Subscriber;

final class Mailer implements Subscriber
{
    public function listeners(): iterable
    {
        yield $this->onRegistered(...);
        yield $this->onPlaced(...);
    }

    private function onRegistered(UserRegistered $event): void { /* ... */ }

    private function onPlaced(OrderPlaced|OrderCancelled $event): void { /* ... */ }
}

$dispatcher = new Dispatcher([new Mailer(), $otherListener]);
```

Its listeners take the place of the object, in the order it gives them, and the constructor checks each
as any listener. `listeners()` is called once, by the constructor, and may return any iterable.
A subscriber is never called itself, an invokable one too. Its listeners may be private methods:
PHPStan reports one that `listeners()` does not give as unused.

## Dispatching

`dispatch()` calls the listeners of the event one by one, in the order they are given to the constructor.
It takes any iterable of them (an array, a generator, a tagged collection of a container), the keys are ignored.
There are no priorities and no stopping. What a listener throws is thrown from `dispatch()`, and the listeners
after it are not called. A listener may suspend the fiber, and then `dispatch()` waits for it: the next listener
is called after it returns.

An event dispatched by a listener goes to its listeners at once, before the next listener of the first event
is called. So a listener that stands after the one that dispatches gets the new event before the one
that has caused it: put a listener that dispatches after those that take both events.

The listeners of a class are found on its first event and kept, so every next event costs one lookup by its class.
A dispatcher is immutable: to add listeners, create another one, `new Dispatcher([...$listeners, ...$more])`.

`listens()` tells whether any listener gets the events of a class, to skip building an event that nobody gets:

```php
if ($dispatcher->listens(QueryExecuted::class)) {
    $dispatcher->dispatch(new QueryExecuted($sql, $elapsed, backtrace()));
}
```
