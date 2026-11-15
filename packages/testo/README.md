# Thesis Testo

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/testo/php)](https://packagist.org/packages/thesis/testo)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/testo)](https://github.com/thesis-php/testo/releases)

Plugins for [Testo](https://github.com/php-testo/testo) to test code built on thesis packages.

## Installation

```shell
composer require --dev thesis/testo
```

Requires 64-bit PHP 8.5+.

## Virtual time

`VirtualTimePlugin` and the `#[VirtualTime]` attribute run a test in its own `Thesis\Async\VirtualTime\run()`
of [thesis/async](https://github.com/thesis-php/async): the clock jumps to the next timer whenever nothing can run,
so `delay(hours(1))` is over at once and durations can be asserted exactly. How the virtual clock behaves is described
in the README of thesis/async.

Every test of a suite, with the plugin in `testo.php`:

```php
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Thesis\Testo\VirtualTimePlugin;

return new ApplicationConfig(
    suites: [
        new SuiteConfig(name: 'unit', location: [__DIR__ . '/tests'], plugins: [new VirtualTimePlugin()]),
    ],
);
```

Or only some, with the attribute on a test class or a test:

```php
use Testo\Assert;
use Testo\Test;
use Thesis\Async\Instant;
use Thesis\Testo\VirtualTime;
use function Thesis\Async\delay;
use function Thesis\Time\hours;

#[Test]
final class HeartbeatTest
{
    #[VirtualTime]
    public function waitsAnHour(): void
    {
        $start = Instant::now();

        delay(hours(1));

        Assert::equals(Instant::now()->since($start), hours(1));
    }
}
```

The lifecycle hooks of a test (`#[BeforeTest]`, `#[AfterTest]`) run on its clock, and every data set gets a clock
of its own. A test that has the attribute in a suite with the plugin is not wrapped twice.
