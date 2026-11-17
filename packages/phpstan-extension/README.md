# Thesis PHPStan Extension

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/phpstan-extension/php)](https://packagist.org/packages/thesis/phpstan-extension)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/phpstan-extension)](https://github.com/thesis-php/phpstan-extension/releases)

A [PHPStan](https://phpstan.org) extension for the thesis packages: precise types where the generics of PHPDoc
cannot express them, and checks of what PHP itself cannot check. One extension for all packages: it does nothing for a package you do not use.

## Installation

```shell
composer require --dev thesis/phpstan-extension
```

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer) that is all.
Otherwise include the config:

```neon
includes:
    - vendor/thesis/phpstan-extension/extension.neon
```

Requires PHPStan 2.3+.

## thesis/async

`all()` and `allSettled()` of an array shape of callables return the shape of their results, a type per key:

```php
use function PHPStan\Testing\assertType;
use function Thesis\Async\all;
use function Thesis\Async\allSettled;

$report = all([
    'sales' => loadSales(...),
    'costs' => loadCosts(...),
]);
assertType('array{sales: Sales, costs: Costs}', $report);
// without the extension: array<'sales'|'costs', Sales|Costs>

[$results, $errors] = allSettled(['sales' => loadSales(...), 'costs' => loadCosts(...)]);
assertType('array{sales?: Sales, costs?: Costs}', $results);
assertType('array{sales?: Throwable, costs?: Throwable}', $errors);
```

`all()` and `map()` keep what is known of the keys of their input: a shape gives a shape, a list gives a list,
a non-empty array a non-empty one.

```php
use function Thesis\Async\map;

/** @var non-empty-list<string> $urls */
assertType('non-empty-list<Response>', map($urls, 10, fetch(...)));
// without the extension: array<int<0, max>, Response>

assertType('array{sales: Report, costs: Report}', map(['sales' => $sales, 'costs' => $costs], 2, report(...)));
// without the extension: array<'sales'|'costs', Report>
```

Without such a key or for a generator, the type of the PHPDoc stays. `race()`, `timeout()`, `group()` and the others
need no extension: their generics are precise.

## thesis/dispatcher

A method marked `#[Listener]` must be public and have a body. The dispatcher calls it with the event and itself,
so its second parameter, if any, must take `Thesis\Dispatcher`, and the ones after it must be optional:

```php
abstract class Mailer
{
    #[Listener] // thesis.listenerNotPublic: listenersOf() rejects it
    protected function onRegistered(UserRegistered $event): void { /* ... */ }

    #[Listener] // thesis.listenerAbstract: the mark is not inherited, so its implementations are not listeners
    abstract public function onPlaced(OrderPlaced $event): void;

    #[Listener]
    public function onCancelled(
        OrderCancelled $event,
        int $attempt = 1, // thesis.listenerSecondParameter: it would get the dispatcher
    ): void { /* ... */ }

    #[Listener]
    public function onShipped(
        OrderShipped $event,
        Dispatcher $dispatcher,
        Clock $clock, // thesis.listenerRequiredParameter: the dispatcher passes the event and itself only
    ): void { /* ... */ }
}
```

`listenersOf()` finds the first error only when it runs, and the second never. The last two fail
only on the first event of the listener.
