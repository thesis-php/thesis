# thesis/time

A polyfill of `Time\Duration` of PHP 8.6 for PHP 8.2 to 8.5, functions for it and PSR-20 clocks,
on 64-bit and 32-bit PHP. The API is in `README.md`, the class in https://wiki.php.net/rfc/duration_class.

## Invariants that must not be broken

### PHP 8.2

- The package runs on PHP 8.2.2+, the rest of the monorepo on 8.6. 8.2 is the PHP of Debian 12, the main
  32-bit system (Debian LTS until 2028). Below 8.2 there are no `readonly` classes and no Testo.
- The code, tests included, has no syntax or API newer than 8.2: no types of class constants (kept off
  by a `RectorSkip` of the manifest), no `public private(set)`, no `new X()->call()` (a variable keeps the fixer
  from making it), no `getMicrosecond()`, no non-constant initializer of a `static` variable. PHPStan and Rector
  take the PHP of the monorepo and miss it, `make test-alone P=time` on 8.2 to 8.5 catches it.
  The rules of `agents/php.md` give way to this.
- 8.2.2, not 8.2: before it `modify('@...')` of `shift()` moved a time by its UTC offset (php-src GH-9891).
  CI runs only the latest patch of each minor, so a bound on a patch is checked by hand on its image
  (`php:8.2.0-cli-alpine`).
- No `round()` of a float: before 8.4 it rounds to 15 digits first (0.49999999999999994 becomes 1) and leaves
  a value of 1e15 and above with its fraction. `roundHalfAwayFromZero()` is exact, `FunctionsTest` pins it.
- `\RoundingMode` comes from `symfony/polyfill-php84` below 8.4. It is only the enum: the test that compares
  our rounding with `round()` is skipped there.

### The polyfill

- `polyfill/` behaves as the C class (`ext/date/time_duration.c`, `lib/duration.c`, `lib/parse_iso_intervals.re`
  of timelib in php-src) down to the exception classes, messages and previous exceptions. When they differ,
  the C code is right: read it, do not guess.
- `polyfill/` holds only `Time\Duration`, `Time\TimeException` and `load.php`, which loads them:
  the namespace belongs to PHP. The rest lives in `Thesis\Time\Internal` (`IsoInterval`).
- `$signedSeconds` and `$signedNanoseconds` are declared first: PHP compares objects property by property
  in declaration order, which makes `<`, `==` and `<=>` work as in 8.6. Two ints, since int nanoseconds
  do not fit on 32-bit. `__serialize()` leaves them out.
- `__unserialize()` assigns the properties as they come, like `object_properties_load()`, so the engine throws
  the `TypeError` or `Error` that 8.6 wraps as the previous exception.
- Messages name `Time\Duration` literally, not by `self::class`: `DurationFuzzTest` loads a renamed copy.
- `Time\TimeException` is not final, as in 8.6: the manifest keeps the file from `final_class` of the fixer.

### 32-bit PHP

- No `php-64bit` (`agents/platform.md`). On 32-bit the class ends at `PHP_INT_MAX` seconds (about 68 years)
  and never throws "Integer Overflow": the factor and the seconds are below 2^31. `PHP_INT_SIZE` is checked
  only in `MAX_SECONDS`, the messages and `exceedsUint64()`, the rest is one path for both.
- No arithmetic in int nanoseconds, which hold 2.1 seconds on 32-bit: compute in seconds and nanoseconds.
  A product wider than int (`multiplyBy()`, `divideBy()`) is done by doubling and binary division.
  Integer conversions round by the remainder (`Internal\rounded()`), never through a float.
- An int that overflows becomes a float, and that float is the check: an `int|float` parameter (the constructor
  of the polyfill, `fromParts()`, `rounded()`) rejects it or compares it with the range. Never on a local
  variable: PHPStan types `int * int` as `int` and reports `is_float()` as always false.
- A float of nanoseconds (`fromFloat()`) is split by `fmod()` and a rounded quotient: above 2^62 ns a float
  is a multiple of 1024 and a truncated quotient loses a second. A finite value times the unit may be INF.
- Timestamps come from `format('U')` (`Internal\timestamp()`), never `getTimestamp()`, which throws on 32-bit
  outside of 1901 to 2038. A float beyond int becomes a string by `whole()`: `%s` gives 14 digits.
- The `to*()` conversions throw `Time\TimeException` for a magnitude above `PHP_INT_MAX`, which happens only
  on 32-bit. `toNs(ns(PHP_INT_MIN))` throws too: one value is not worth a branch.
- Functions throw `Time\TimeException` out of range, as the class does, so a caller handles one kind.

## Decisions

- Functions, not a class: `Time\Duration` is final, and a wrapper is a second duration type at every boundary
  (the old `thesis/time-span`).
- Short names as in Go: `ns()` to `hours()` create, `to*()` convert. A full unit is plural, it takes an amount
  (`minutes(1.5)`). `toMs()`, not `inMs()` or `asMs()`: `to` says the value is converted, maybe with a loss.
- The functions take a negative value, unlike the factories of the class: a duration is negative anyway
  after `sub()`, `negate()` or `between()`, and `shift($now, sec(-30))` beats `sec(30)->negate()`.
  A caller that needs a positive duration (a timeout) checks it with `$duration > sec(0)`.
- No duration constants, not even `ZERO`: only `define()` could make one, a default of a duration parameter
  is `null`, and `$duration === ZERO` compares identity (and sometimes passes, 8.6 caches the last duration).
  The check is `isZero()`. Rejected for the same reason: `zero()` with a `static` instance, which makes
  `=== zero()` pass for its own result and fail for a computed zero (`sub($a, $a)`).
- No sign functions but `isZero()`: `<`, `>` and `<=>` compare durations, and the class has `$negative`.
  `isZero()` stays because `==` would be made `===` by the fixer. Rejected: `isPositive()`
  and `isNegative()` (removed in 0.5), a second way to write `$duration > sec(0)` and `$duration->negative`.
- `multiply()` stays next to `multiplyBy()`: it takes a float and a negative factor, which the class does not.
  Rejected: naming it `multiplyBy()`: one name for two contracts (the method throws on a negative factor),
  and as a function `multiplyBy($delay, 2)` reads as multiplying by `$delay`. Rejected: a `\RoundingMode`:
  the result is a duration rounded to a nanosecond, as `sec(1.5)` is, and a mode matters only where
  a conversion rounds to the unit of an API (`toMs()`). An optional one can be added later without a break.
- No function named after a built-in (`min()`, `max()`): imported, it shadows the built-in in the whole file
  and ignores extra arguments. Hence `minutes()`, `shortest()` and `longest()`.
- The integer conversions require a `\RoundingMode`, with no default. Their main use is a timeout, a TTL
  or an interval for an API of whole units, which must round up: a fraction dropped makes it expire early,
  and `0` is "no limit" in curl, Postgres, AMQP heartbeats. Elapsed time rounds toward zero. One default
  hides the choice, and the consumers that round inside (`Io\Poll::wait()`, Go netpoll, tokio) are what
  the caller of `toMs()` is. Rejected: `TowardsZero` by default (the getters of Go, Java, Rust): no caller
  in the monorepo used it. Rejected: a function per direction: `\RoundingMode` already names them.
  A default can be added later without a break.
- Every unit has a float conversion of its own (`toMsFloat()`), named apart from the int one. Rejected:
  a float from `toMs($duration)` without a `\RoundingMode` and an int with it (as `toMilliseconds($precision)`
  of `thesis/time-span`): a forgotten mode is no longer an `ArgumentCountError` but a float that `sprintf('%d')`,
  `(int)` and a coercive int parameter truncate silently, and the type of the result depends on an argument.
- A float conversion divides the nanoseconds once: dividing a float of seconds by 60 rounds twice and was
  off by a unit in the last place for a quarter of the durations with a fraction (`toMinutesFloat(minutes(0.009))`
  gave `0.009000000000000001`). Above 2^53 nanoseconds (104 days) a float no longer holds them, so the whole units
  stay exact and only the fraction is divided: rarely an ulp off (above 2^53 µs, 285 years, for `toUsFloat()`).
  Rejected: the nearest float there too, it needs integer arithmetic wider than 64 bits for durations nobody has.
- `parse()` takes the grammar of `time.ParseDuration()` of Go and nothing else: one known contract, which reads
  what `format()` writes and the strings of Go services and Kubernetes. Hence `0` alone, units in any order
  and twice, `.5s`, and no spaces, no days (`1d`), no `sec` or `min`. A string that is not a duration throws
  `Time\TimeException`, as `fromIso8601DurationString()` does.
- A number of `parse()` is a float converted by `fromFloat()`, so `parse('1.5h')` is `hours(1.5)`, on 64-bit
  and 32-bit alike. Whatever `format()` writes comes back exactly: its hours and minutes are whole, its seconds
  are below 60. Rejected: arithmetic in decimal digits (the digits times 6 or 36, then the point moved), which
  was exact for a number of any length: 35 lines for numbers of more than 15 significant digits that nobody writes.
- No days: like the class, a duration has no calendar units. No monotonic clock: a point in time is not
  a duration (the `Time\Instant` RFC).
- `between()` and `shift()` work on timestamps: elapsed time does not stretch with DST. `shift()` drops
  nanoseconds toward zero and takes the time first, as `multiply()` takes the duration first.
  Rejected: `after()`/`before()`, which break that order, `plus()`/`minus()`, two functions for one operation,
  and `fromInterval(\DateInterval)`, whose day is a calendar day.
- `WallClock` and `FrozenClock` live here: a clock and a duration go together (`$clock->advance(sec(30))`),
  and `psr/clock` is an interface only. `FrozenClock` moves only when told: an implicit tick makes tests
  depend on the order of calls.
- The polyfill is loaded eagerly by `polyfill/load.php` behind `class_exists()`. Rejected: a `classmap`
  of `polyfill/`, as the stubs of `symfony/polyfill-*`. At runtime 8.6 never reaches it, but PHPStan of a user
  on 8.6 finds `Time\Duration` in the classmap and takes the polyfill for the class of PHP (its private
  constructor, its methods), even behind `if (\PHP_VERSION_ID < 80600)`. A PSR-4 entry for `Time\` would
  also claim a namespace of PHP.
- The ISO 8601 parser replicates the scanner of timelib: `\DateInterval` drops the start, the end
  and the recurrences, which the class rejects with messages of their own.
- Accepted: on 32-bit a timestamp beyond ±2^53 seconds (year ±285 million) is a float off by a second
  in `between()` and `shift()`: exact int64 in strings costs code for dates nobody has.
- Rejected: matching the C class on data that `serialize()` never writes: mangled keys (accepted by 8.6),
  a reference between values (rejected by it), `__unserialize([])` on a live duration (a no-op there).
  It costs the unmangling, scopes and references of `object_properties_load()` for input nobody makes.
  What `serialize()` writes, wrong types and values, missing and extra keys behave as in 8.6.

## Tests

- `DurationTest` pins the class with values from PHP 8.6 and passes against both: on 8.6 by `make test P=time`,
  against the polyfill by `PHP_VERSION=8.2 make test-alone P=time` (CI: 8.2 to 8.5). `outcome()` includes
  the messages of the previous exceptions.
- 32-bit: `PHP_VERSION=8.2 ARCH=386 make test-alone P=time`, and `IMAGE=php:8.6-rc-cli` for 8.6 until its release
  (ours has no i386 build), where `DurationFuzzTest` compares with the 32-bit class. Testo runs on 32-bit
  since 0.10.60 (before it ordered interceptors by numbers above 2^31, php-testo/testo#418), hence the constraint.
  A literal above 2^31 is a float on 32-bit, so the bounds of each platform have their own provider
  (`factoriesOf32Bits()`) with values from the class of that platform.
- Mutation testing runs on 8.6, without the polyfill: `IsoInterval` is in `src/` and tested directly.
- `DurationFuzzTest` runs on 8.6 only: it compares a renamed copy of the polyfill (`loadPolyfillCopy()`)
  with the class on random input, ISO strings built from the grammar and then broken. Boundaries random input
  does not hit (a product of exactly 2^64 - 1) go to `DurationTest`.
- Loose equality is checked by `Assert::equals()`: the fixer turns `==` into `===`, which can pass by accident
  since 8.6 caches the last duration.
