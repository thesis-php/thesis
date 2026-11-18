# Thesis Time

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/time/php)](https://packagist.org/packages/thesis/time)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/time)](https://github.com/thesis-php/time/releases)

Short functions for [`Time\Duration`](https://wiki.php.net/rfc/duration_class) of PHP 8.6, a polyfill of it
for PHP 8.2 to 8.5, and [PSR-20](https://www.php-fig.org/psr/psr-20/) clocks.

```php
use function Thesis\Time\{format, ms, sec, toMs, toSecFloat};

$timeout = sec(1.5);

toMs($timeout);       // 1500
toSecFloat($timeout); // 1.5
format($timeout);     // 1.5s
```

## Installation

```shell
composer require thesis/time
```

Requires PHP 8.2+, 64-bit or 32-bit. No extensions. Below PHP 8.4 `\RoundingMode` comes from `symfony/polyfill-php84`.

## Why

`Time\Duration` is a fixed amount of elapsed time with nanosecond precision: a timeout, a delay, a TTL.
It is the type the ecosystem will share, but it is minimal on purpose: its factories take only non-negative ints,
and nothing converts it back to a number. This package fills the gaps with functions and does not wrap the class:
every function takes and returns `Time\Duration`, so it passes to any other API as is.

## Polyfill

Below PHP 8.6 the package declares `Time\Duration` and `Time\TimeException`. They behave as the classes of PHP 8.6
down to the exception messages: factories, arithmetic, `compare()`, the `<`, `==` and `<=>` operators,
ISO 8601 parsing and `serialize()`. A test compares the two on random input.

What differs: `var_dump()`, `var_export()` and an `(array)` cast show one more private property,
reflection sees `__serialize()`, and the error of a wrong argument type mentions the file of the caller.
On PHP 8.6 the polyfill is not loaded.

## Creating

Each function takes a negative value too, and a float, rounded to the nearest nanosecond, half away from zero.

```php
use function Thesis\Time\{duration, hours, minutes, ms, ns, sec, us};

ns(15);
us(1.5);
ms(-250);
sec(90);
minutes(1.5);
hours(0.25);

duration(minutes: 1, sec: 30); // the sum of the parts
```

A value out of the range of `Time\Duration` (about ±292 years, ±68 years on 32-bit PHP) throws `Time\TimeException`,
`NAN` and `INF` throw `\ValueError`.

## Converting

```php
use function Thesis\Time\{toHours, toMinutes, toMs, toNs, toSec, toSecFloat, toUs};

toNs($duration);  // exact: on 64-bit PHP every duration fits in int nanoseconds
toMs($duration);  // whole milliseconds, the fraction is dropped
toSec($ttl, RoundingMode::PositiveInfinity); // whole seconds, rounded up
toSecFloat($timeout); // for APIs that take float seconds, like the timers of Revolt
```

`toUs()`, `toMs()`, `toSec()`, `toMinutes()` and `toHours()` round with a `\RoundingMode`, `TowardsZero` by default
as in Go, Java and Rust. A timeout is usually rounded up, so that it never expires early. On 32-bit PHP a result
above `PHP_INT_MAX` throws `Time\TimeException`: an int holds 2.1 seconds of nanoseconds, 35 minutes of microseconds
and 24 days of milliseconds.

## The rest

```php
use function Thesis\Time\{format, isNegative, isPositive, isZero, longest, multiply, shortest};

isZero($duration);
isPositive($duration);
isNegative($duration);

shortest($a, $b, $c);
longest($a, $b, $c);

multiply($delay, 2);   // exact
multiply($delay, 1.5); // rounded to the nearest nanosecond
multiply($delay, -1);

format(duration(hours: 1, minutes: 2, sec: 3.5)); // 1h2m3.5s, like Duration.String() of Go
```

## Points in time

`between()` and `shift()` connect durations with `DateTimeImmutable`. They count elapsed time
from the timestamps, so a change of the UTC offset (DST) in between does not count.
`DateTimeImmutable` keeps microseconds: `shift()` drops the rest of the nanoseconds.

```php
use function Thesis\Time\{between, shift};

$expiresAt = shift($clock->now(), $ttl);
$retainedSince = shift($clock->now(), $retention->negate());
$elapsed = between($startedAt, $clock->now());
```

## Clocks

`WallClock` and `FrozenClock` implement `Psr\Clock\ClockInterface`.

```php
use Thesis\Time\{FrozenClock, WallClock};
use function Thesis\Time\sec;

$clock = new WallClock();                             // the system time in the default time zone
$clock = new WallClock(new DateTimeZone('UTC'));

$clock = new FrozenClock(new DateTimeImmutable('2026-01-01 00:00:00 UTC')); // for tests
$clock->advance(sec(30));
$clock->now = new DateTimeImmutable('2027-01-01 00:00:00 UTC');
```

## Durations themselves

`Time\Duration` itself adds, subtracts and compares: `$a->add($b)`, `$a->sub($b)`, `$a->negate()`,
`$a->absolute()`, `Duration::compare($a, $b)`, `$a < $b`.
