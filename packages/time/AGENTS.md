# thesis/time

Functions for `Time\Duration` of PHP 8.6, a polyfill of it for PHP 8.2 to 8.5, and PSR-20 clocks,
on 64-bit and 32-bit PHP.
The API is described in `README.md`, the class itself in https://wiki.php.net/rfc/duration_class.

## Invariants that must not be broken

- The package runs on PHP 8.2, while the rest of the monorepo requires 8.6: the polyfill is for those who
  cannot have 8.6 yet. 8.2 is the PHP of Debian 12 (and Raspberry Pi OS), the main system of 32-bit PHP,
  with security fixes of Debian LTS until 2028, though PHP itself ends them with 2026. Below 8.2 there are
  no `readonly` classes and Testo does not run.
  Its code, the tests included, has no syntax and no API newer than 8.2: no types of class constants
  (a `RectorSkip` of the manifest keeps them off), no `public private(set)`, no `new X()->call()`,
  no `DateTime::getMicrosecond()`, no initializer of a `static` variable but a constant expression.
  The tools do not see it, PHPStan and Rector take the PHP of the monorepo: PHP CS Fixer is kept off
  `(new X())->call()` by a variable, and `make test-alone P=time` on 8.2 to 8.5 catches the rest.
  The rules of `agents/php.md` give way to this.
- `\RoundingMode` of PHP 8.4, which the functions take, comes from `symfony/polyfill-php84` below 8.4.
  Only the enum is there: `round()` takes it since 8.4, the test that compares the rounding with it is skipped below.

- The polyfill (`polyfill/`) behaves as the C class (`ext/date/time_duration.c`, `lib/duration.c` and
  `lib/parse_iso_intervals.re` of timelib in php-src) down to the exception classes, messages and previous
  exceptions. When they differ, the C code is right: read it, do not guess.
- `polyfill/` holds only `Time\Duration` and `Time\TimeException`: the namespace belongs to PHP. Anything else
  the polyfill needs lives in `Thesis\Time\Internal` (`IsoInterval`), so deleting `polyfill/` and `IsoInterval`
  is all it takes to require PHP 8.6.
- `Duration::$signedSeconds` and `$signedNanoseconds` are the first declared properties: PHP compares objects
  of one class property by property in declaration order, so they make `<`, `==` and `<=>` work as in PHP 8.6.
  Two ints, not one of nanoseconds, which does not fit on 32-bit PHP. `__serialize()` leaves them out,
  so the serialized form is the one of PHP 8.6.
- `__unserialize()` assigns the properties as they come, like `object_properties_load()`: the engine throws
  the same `TypeError` or `Error` that PHP 8.6 wraps as the previous exception.
- The messages of the polyfill name `Time\Duration` literally, not with `__METHOD__` or `self::class`:
  `DurationFuzzTest` loads a renamed copy, and the messages must still match.
- `Time\TimeException` is not final, as in PHP 8.6: the manifest excludes the file from PHP CS Fixer (`final_class`).
- The package runs on 32-bit PHP, as `Time\Duration` of PHP 8.6 does there: the manifest drops `php-64bit`
  (`withoutPlatform`), the rest of the monorepo is 64-bit only (`agents/platform.md`). On 32-bit the class ends
  at `PHP_INT_MAX` seconds (`2_147_483_647 seconds (roughly 68 years)`) and never throws "Integer Overflow":
  the factor and the seconds are below 2^31. The branches on `PHP_INT_SIZE` are the ternaries of `MAX_SECONDS`
  and the messages, and `exceedsUint64()`, whose formula takes PHP_INT_MAX for 2^63 - 1. The rest of the code
  is one path for both.
- No arithmetic in int nanoseconds: on 32-bit PHP they hold 2.1 seconds. The polyfill and the functions compute
  in seconds and nanoseconds; where a product is wider than int (`multiplyBy()`, `divideBy()`), the polyfill
  multiplies by doubling and divides in binary, exact on both platforms. Integer conversions round exactly
  by the remainder (`Internal\rounded()`), never through a float.
- An int that overflows becomes a float, and that float is the check: a parameter `int|float` (the constructor
  of the polyfill, `fromParts()`, `rounded()`) rejects it or compares it with the range. PHPStan types `int * int`
  as `int`, so the check is never on a local variable (`is_float()` there is "always false"). On 64-bit PHP
  nothing in range overflows, so the floats appear only on 32-bit.
- A float of nanoseconds (`fromFloat()`) is split by `fmod()`, exact, and a rounded quotient: above 2^62
  nanoseconds (146 years) the float is a multiple of 1024, an odd second is not, and a truncated quotient
  loses a second. A finite value times the unit may be INF, it is out of range, not `(int) NAN`, zero.
- Timestamps come from `format('U')` (`Internal\timestamp()`), never `getTimestamp()`: on 32-bit PHP it throws
  `DateRangeError` outside of 1901 to 2038, while `DateTimeImmutable` holds int64. A float beyond int becomes
  a string by `whole()`: `%s` of a float has 14 digits.
- `toNs()`, `toUs()`, `toMs()` and the others throw `Time\TimeException` ("does not fit in int") for a magnitude
  above `PHP_INT_MAX`, on 32-bit PHP only. `toNs(ns(PHP_INT_MIN))` throws too: the magnitude is rounded first,
  and one value is not worth a branch.
- Functions throw `Time\TimeException` for a value out of range, the same exception the class throws,
  so a caller handles one kind.

## Decisions

- Accepted: on 32-bit PHP a timestamp beyond ±2^53 seconds (year ±285 million) is a float off by one second
  in `between()` and `shift()`. Exact int64 arithmetic in strings costs code for dates nobody has.
- Functions, not a class: `Time\Duration` is final, and a wrapper would be a second duration type at every
  API boundary. Rejected: our own `TimeSpan`-like class (the old `thesis/time-span`).
- Short names, like Go: `ns()`, `us()`, `ms()`, `sec()`, `minutes()`, `hours()` create, `to*()` convert.
  A full unit is plural: the function takes an amount (`minutes(1.5)`), unlike the unit constants of Go
  (`5 * time.Minute`). `toMs()`, not `inMs()` or `asMs()`: `to` says the value is converted, possibly with a loss.
- No duration constants, not even `ZERO`: `Time\Duration` has no constructor for `const` and `Duration::ZERO`
  belongs to PHP, so only `define()` is left. A default of a duration parameter is `null` ("no limit"), not zero,
  so a constant would replace only `ns(0)`, and it invites `$duration === ZERO`, which compares identity
  (and sometimes passes, PHP 8.6 caches the last duration). The check is `isZero()`.
- No function is named after a built-in one (`min()`, `max()`): imported, it shadows the built-in in the whole
  file, and a userland function ignores extra arguments, so `min($a, $b)` silently returns a duration.
  Hence `minutes()`, and `shortest()` and `longest()` for the minimum and the maximum.
- The integer conversions take a `\RoundingMode`, `TowardsZero` by default as in Go, Java and Rust.
  The only float conversion is `toSecFloat()`: seconds are what float APIs (Revolt) take.
- No days: like the class, a duration has no calendar units.
- No monotonic clock (`hrtime()` as a duration): a point in time is not a duration,
  see the `Time\Instant` RFC under discussion.
- `between()` and `shift()` work on timestamps, not on the wall clock: a duration is elapsed time,
  so DST must not stretch or shrink it. `shift()` drops nanoseconds toward zero, like `toUs()`.
  `shift($time, $duration)` takes the time first, as `multiply($duration, $factor)` takes what it returns first;
  a negative duration moves back. Rejected: `after($duration, $time)` and `before()`, which read as English
  but break that order, and `plus()`/`minus()`, two functions for one operation.
  Rejected: `fromInterval(\DateInterval)` of the old `thesis/time-span`: a day of `\DateInterval` is
  a calendar day, and an interval of `diff()` carries DST.
- `WallClock` (from the old `thesis/clock`) and `FrozenClock` live here: a clock and a duration go together
  (`$clock->advance(sec(30))`, `shift($clock->now(), $ttl)`), and `psr/clock` is an interface only.
  `FrozenClock` moves only when told: an implicit tick makes tests depend on the order of calls.
- The polyfill is loaded eagerly by `src/polyfill.php` behind `class_exists()`: Composer has no conditional
  autoload, and a PSR-4 entry for `Time\` would claim a namespace of PHP.
- The ISO 8601 parser replicates the timelib scanner instead of using `\DateInterval`: `\DateInterval` drops
  the start, the end and the recurrences, which the class rejects with their own messages.

## Tests

- `DurationTest` pins the class with values taken from PHP 8.6 and passes against both: `make test P=time`
  runs it on 8.6, `PHP_VERSION=8.2 make test-alone P=time` installs the package alone and runs it
  against the polyfill (CI does it on 8.2 to 8.5).
- The tests run on 32-bit PHP too (`PHP_VERSION=8.2 ARCH=386 make test-alone P=time`, `IMAGE=php:8.6-rc-cli`
  for 8.6 until its release, on the official image: ours has no i386 build), on 8.2 with the polyfill and on 8.6,
  where `DurationFuzzTest` compares the polyfill with the class of 32-bit PHP 8.6. Testo 0.10.59 orders its
  interceptors by numbers above 2^31 (php-testo/testo#418, fixed in its main): until a release the Makefile
  patches them in the vendor of the copy and fails once a release changes them, then the patch goes.
  The bounds of 64-bit and 32-bit PHP are in their own providers (`factoriesOf32Bits()`), yielded by the common
  one: a literal above 2^31 is a float on 32-bit PHP, so it stays out of the cases of both. The 32-bit values
  are taken from the class of 32-bit PHP 8.6.
  The cases of dates and floats cover both platforms at once: beyond int timestamps and above 2^62 nanoseconds.
- Mutation testing runs on 8.6, where the polyfill is not loaded: `IsoInterval` is in `src/` and its tests
  call it directly, so they kill its mutants without the polyfill.
- `DurationFuzzTest` runs on PHP 8.6 only: it loads a copy of the polyfill as `Thesis\Time\Polyfill\Duration`
  (`loadPolyfillCopy()`) and compares it with the original on random input. Random pieces rarely form a period,
  so ISO strings are also built from the grammar and then broken. Boundaries that random input does not hit
  (a product of exactly 2^64 - 1 in `multiplyBy()`) go to `DurationTest`.
- PHP CS Fixer turns `==` into `===`, and PHP 8.6 caches the last created duration, so `===` can pass by accident:
  loose equality is checked with `Assert::equals()`.
