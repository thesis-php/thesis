# Thesis Dispatcher

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/dispatcher/php)](https://packagist.org/packages/thesis/dispatcher)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/dispatcher)](https://github.com/thesis-php/dispatcher/releases)

Synchronous event dispatcher: a listener gets the events of the type of its parameter, resolved once per class.

```php
use Thesis\Dispatcher\Dispatcher;

$dispatcher = new Dispatcher(
    static function (UserRegistered $event): void { /* ... */ },
    static function (OrderPlaced|OrderCancelled $event): void { /* ... */ },
    new AuditLog(), // __invoke(DomainEvent $event)
);

$dispatcher->dispatch(new UserRegistered('user@example.com'));
```

## Installation

```shell
composer require thesis/dispatcher
```

Requires 64-bit PHP 8.5+. No extensions.

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
`null`), a scalar, a class or an interface that does not exist, a variadic or a reference parameter, a second
parameter that is not optional.

## Dispatching

`dispatch()` calls the listeners of the event one by one, in the order they are given to the constructor.
There are no priorities and no stopping. What a listener throws is thrown from `dispatch()`, and the listeners
after it are not called. A listener may suspend the fiber, and then `dispatch()` waits for it: the next listener
is called after it returns.

The listeners of a class are found on its first event and kept, so every next event costs one lookup by its class.
A dispatcher is immutable: to add listeners, create another one, `new Dispatcher(...$listeners, $more)`.

`listens()` tells whether any listener gets the events of a class, to skip building an event that nobody gets:

```php
if ($dispatcher->listens(QueryExecuted::class)) {
    $dispatcher->dispatch(new QueryExecuted($sql, $elapsed, backtrace()));
}
```
