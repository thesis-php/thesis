# thesis/time

Functions for `Time\Duration` of PHP 8.6, a polyfill of it for PHP 8.5, and PSR-20 clocks. The API is described in `README.md`,
the class itself in https://wiki.php.net/rfc/duration_class.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- The polyfill (`polyfill/`) behaves as the C class (`ext/date/time_duration.c`, `lib/duration.c` and
  `lib/parse_iso_intervals.re` of timelib in php-src) down to the exception classes, messages and previous
  exceptions. When they differ, the C code is right: read it, do not guess.
- `polyfill/` holds only `Time\Duration` and `Time\TimeException`: the namespace belongs to PHP. Anything else
  the polyfill needs lives in `Thesis\Time\Internal` (`IsoInterval`), so deleting `polyfill/` and `IsoInterval`
  is all it takes to drop PHP 8.5.
- `Duration::$signedNanoseconds` is the first declared property: PHP compares objects of one class property
  by property in declaration order, so this is what makes `<`, `==` and `<=>` work as in PHP 8.6.
  `__serialize()` leaves it out, so the serialized form is the one of PHP 8.6.
- `__unserialize()` assigns the properties as they come, like `object_properties_load()`: the engine throws
  the same `TypeError` or `Error` that PHP 8.6 wraps as the previous exception.
- The messages of the polyfill name `Time\Duration` literally, not with `__METHOD__` or `self::class`:
  `DurationFuzzTest` loads a renamed copy, and the messages must still match.
- `Time\TimeException` is not final, as in PHP 8.6: the manifest excludes the file from PHP CS Fixer (`final_class`).
- Every duration fits in int nanoseconds (the range ends at 9_223_372_035.999999999 s, below `PHP_INT_MAX`):
  `toNs()` is exact, and the functions compute in nanoseconds. Integer conversions round exactly with
  `Internal\divide()`, never through a float.
- Functions throw `Time\TimeException` for a value out of range, the same exception the class throws,
  so a caller handles one kind.

## Decisions

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
- The polyfill is loaded eagerly by `src/autoload.files.php` behind `class_exists()`: Composer has no conditional
  autoload, and a PSR-4 entry for `Time\` would claim a namespace of PHP.
- The ISO 8601 parser replicates the timelib scanner instead of using `\DateInterval`: `\DateInterval` drops
  the start, the end and the recurrences, which the class rejects with their own messages.

## Tests

- `DurationTest` pins the class with values taken from PHP 8.6 and passes against both: run it with
  `PHP_VERSION=8.5` and `PHP_VERSION=8.6` (`PHP_VERSION=8.6 make test P=time`).
- `DurationFuzzTest` runs on PHP 8.6 only: it loads a copy of the polyfill as `Thesis\Time\Polyfill\Duration`
  (`loadPolyfillCopy()`) and compares it with the original on random input. Random pieces rarely form a period,
  so ISO strings are also built from the grammar and then broken. Boundaries that random input does not hit
  (a product of exactly 2^64 - 1 in `multiplyBy()`) go to `DurationTest`.
- PHP CS Fixer turns `==` into `===`, and PHP 8.6 caches the last created duration, so `===` can pass by accident:
  loose equality is checked with `Assert::equals()`.
