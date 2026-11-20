# thesis/time

A polyfill of `Time\Duration` of PHP 8.6 for PHP 8.2 to 8.5, functions for it and PSR-20 clocks,
on 64-bit and 32-bit PHP. The API is in `README.md`, the class in https://wiki.php.net/rfc/duration_class.

## Invariants

### PHP 8.2

- The package runs on PHP 8.2.2+, the rest of the monorepo on 8.6. 8.2 is the PHP of Debian 12, the main
  32-bit system (Debian LTS until 2028). Below 8.2 there are no `readonly` classes and no Testo.
- The code, tests included, has no syntax or API newer than 8.2, and the rules of `agents/php.md` give way
  to this: no types of class constants (a `RectorSkip` of the manifest), no `public private(set)`,
  no `new X()->call()` (a variable keeps the fixer from making it), no `getMicrosecond()`, no non-constant
  initializer of a `static` variable. PHPStan and Rector take the PHP of the monorepo and miss it,
  `make test-alone P=time` on 8.2 to 8.5 catches it.
- 8.2.2, not 8.2: before it `modify('@...')` of `shiftTime()` moved a time by its UTC offset (php-src GH-9891).
  CI runs only the latest patch of each minor, so a bound on a patch is checked by hand on its image
  (`php:8.2.0-cli-alpine`).
- No `round()` of a float, which is wrong before 8.4: `Internal\roundHalfAwayFromZero()` is exact,
  its docblock says why, `FunctionsTest` pins it.
- `\RoundingMode` comes from `symfony/polyfill-php84` below 8.4. It is only the enum: the test that compares
  our rounding with `round()` is skipped there.

### The polyfill

- `polyfill/` behaves as the C class (`ext/date/time_duration.c`, `lib/duration.c`, `lib/parse_iso_intervals.re`
  of timelib in php-src) down to the exception classes, messages and previous exceptions. When they differ,
  the C code is right: read it, do not guess.
- `polyfill/` holds only `Time\Duration`, `Time\TimeException` and `load.php`, which loads them:
  the namespace belongs to PHP. The rest lives in `Thesis\Time\Internal` (`IsoInterval`).
- `$signedSeconds` and `$signedNanoseconds` are declared first: PHP compares objects property by property
  in declaration order, which makes `<`, `==`, `<=>`, `min()`, `max()` and `sort()` work as in 8.6.
  Two ints, since int nanoseconds do not fit on 32-bit. `__serialize()` leaves them out.
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
  of the polyfill, `fromParts()`, `rounded()`) rejects it or compares it with the range. Never a local
  variable: PHPStan types `int * int` as `int` and reports `is_float()` as always false. The price: PHPStan
  checks nothing of the `non-negative-int` of such a parameter, any int passes as its float
  (`new self(-1, 0, false)` is not reported), so the code that can go below zero asserts it itself
  (`subtractMagnitudes()` of the polyfill). The ranges of the `int` parameters (`$nanoseconds`) are checked.
- Timestamps come from `Internal\timestamp()`, never `getTimestamp()`, which throws on 32-bit outside
  of 1901 to 2038. A float beyond int becomes a string by `whole()`: `%s` gives 14 digits.
- The `to*()` conversions throw `Time\TimeException` for a magnitude above `PHP_INT_MAX`, which happens only
  on 32-bit. `toNs(ns(PHP_INT_MIN))` throws too: one value is not worth a branch. The `to*Float()` ones
  never throw, hence `toNsFloat()`, of no use on 64-bit, where `toNs()` is exact.

## Decisions

### Names

- Functions, not a class: `Time\Duration` is final, and a wrapper is a second duration type at every boundary
  (the old `thesis/time-span`).
- Short names as in Go: `ns()` to `hours()` create, `to*()` convert. A full unit is plural, it takes an amount
  (`minutes(1.5)`). `toMs()`, not `inMs()` or `asMs()`: `to` says the value is converted, maybe with a loss.
- A name reads alone at the call: an imported function has no `time.` before it as in Go, and the types
  of its arguments are not on the line. A unit says time (`sec()`, `toMs()`). Arithmetic means the same
  for any quantity and stays bare (`multiply()`, `ratio()`, `isZero()`). A word that reads another way names
  its subject, as `formatValue()` of `thesis/formatter` does: `parseDuration()`, `formatDuration()`,
  `roundDurationTo()`, `roundTimeTo()`, `durationBetween()` (`between()` is a check of a range in Carbon and SQL),
  `shiftTime()` (`array_shift()`, a shift of bits). The two families read alike: `duration()`
  and `durationBetween()` make a duration, `shiftTime()` and `roundTimeTo()` return a time.
  Rejected: no subject where the type of a parameter gives it (`between()` of two times): the reader of a call
  does not see the types. Rejected: the subject in every name (`multiplyDuration()`, `isZeroDuration()`): longer,
  with no other reading to rule out. Rejected: `elapsed()` for `durationBetween()`: it reads as the time
  until now, which needs a clock, and the result may be negative.
- Rejected: a namespace `Thesis\Time\Duration\` for the functions of a duration. PHP imports a function
  by its short name, so `multiply` still collides with any other `multiply`, and a call with a prefix
  (`Duration\multiply()`) needs `use Thesis\Time\Duration`, a fatal error next to `use Time\Duration`.
- No function named after a built-in (`min()`, `floor()`, `round()`): imported, it shadows the built-in
  in the whole file and ignores extra arguments. Hence `minutes()`.

### What the functions take and what is left out

- The functions take a negative value, unlike the factories of the class: a duration is negative anyway
  after `sub()`, `negate()` or `durationBetween()`, and `shiftTime($now, sec(-30))` beats `sec(30)->negate()`.
  A caller that needs a positive duration (a timeout) checks it with `$duration > sec(0)`.
- They throw `Time\TimeException` out of range, as the class does, so a caller handles one kind,
  and `\ValueError` only for NAN and INF given. `Internal\fromFloat()` tells the two apart by doing
  the multiplication itself: it gets the argument of the caller as the value and the rest as the unit,
  never a product, which overflows to INF and reads as a wrong argument (`multiply()` passes the factor).
- No duration constants, not even `ZERO`: only `define()` could make one, a default of a duration parameter
  is `null`, and `$duration === ZERO` compares identity (and sometimes passes, 8.6 caches the last duration).
  The check is `isZero()`. Rejected for the same reason: `zero()` with a `static` instance, which makes
  `=== zero()` pass for its own result and fail for a computed zero (`sub($a, $a)`).
- No second way to write what PHP has: `<`, `>`, `<=>`, `min()`, `max()` and `sort()` compare durations
  on 8.6 and with the polyfill (`DurationTest` pins it, PHPStan types their result as `Time\Duration`),
  and the class has `$negative`. Rejected, removed in 0.4: `isPositive()`, `isNegative()`, `shortest()`,
  `longest()`. `isZero()` stays because the fixer would make `==` a `===`.
- No days: like the class, a duration has no calendar units. No monotonic clock: a point in time is not
  a duration (the `Time\Instant` RFC).

### Arithmetic

- `multiply()` stays next to `multiplyBy()`: it takes a float and a negative factor, which the method does not.
  Rejected: naming it `multiplyBy()`: one name for two contracts (the method throws on a negative factor),
  and `multiplyBy($delay, 2)` reads as multiplying by `$delay`. Rejected: a `\RoundingMode`: the result
  is rounded to a nanosecond, as `sec(1.5)` is, and a mode matters only where a conversion rounds to the unit
  of an API (`toMs()`). An optional one can be added later without a break.
- `ratio()` divides the float nanoseconds (`toNsFloat()`), one path for 64-bit and 32-bit: they are exact
  below 2^53 (104 days), so the quotient is the nearest float and durations that divide evenly give a whole
  number, which `(int)` keeps. Rejected: `toSecFloat($a) / toSecFloat($b)`, rounded three times
  (`ratio(sec(0.3), sec(0.1))` was 2.9999999999999996, its floor lost an interval). Rejected: `intdiv()`
  and `%` of int nanoseconds, exact above 104 days too: there are none on 32-bit.
- A zero divisor throws the `\DivisionByZeroError` of `/` itself, as `divideBy(0)` does. Rejected: INF and NAN
  as `fdiv()` gives, a progress of NAN goes on silently. Zero by a negative duration is `-0.0`, as `/` gives:
  not worth a branch.

### Conversions

- The integer conversions require a `\RoundingMode`, with no default. Their main use is a timeout, a TTL
  or an interval for an API of whole units, which must round up: a fraction dropped makes it expire early,
  and `0` is "no limit" in curl, Postgres, AMQP heartbeats. Elapsed time rounds toward zero. One default
  hides the choice, and the consumers that round inside (`Io\Poll::wait()`, Go netpoll, tokio) are what
  the caller of `toMs()` is. Rejected: `TowardsZero` by default (the getters of Go, Java, Rust): no caller
  in the monorepo used it. Rejected: a function per direction: `\RoundingMode` already names them.
  A default can be added later without a break.
- The table "Passing to other APIs" of `README.md` is the case for that decision: every int in it rounds up.
  Each row is checked against the source or by a run, the docs do not say it all: the curl handlers of Guzzle
  and Symfony HttpClient multiply float seconds by 1000 for `curl_setopt()`, which truncates (0.9 ms is no limit),
  `PDO::ATTR_TIMEOUT` means what its driver makes of it. Check a new row the same way.
- Every unit has a float conversion of its own (`toMsFloat()`), named apart from the int one. Rejected:
  a float from `toMs($duration)` without a `\RoundingMode` and an int with it (as `toMilliseconds($precision)`
  of `thesis/time-span`): a forgotten mode is no longer an `ArgumentCountError` but a float that `sprintf('%d')`,
  `(int)` and a coercive int parameter truncate silently, and the type of the result depends on an argument.
- A float conversion divides the nanoseconds once: dividing a float of seconds by 60 rounds twice and was
  off by a unit in the last place for a quarter of the durations with a fraction (`toMinutesFloat(minutes(0.009))`
  gave `0.009000000000000001`). Above 2^53 nanoseconds (104 days) a float no longer holds them, so the whole units
  stay exact and only the fraction is divided: rarely an ulp off (above 2^53 µs, 285 years, for `toUsFloat()`).
  Rejected: the nearest float there too, it needs integer arithmetic wider than 64 bits for durations nobody has.

### Rounding to a unit

- `roundDurationTo()` and `roundTimeTo()` round to a multiple of any positive unit (`ms(1500)` too),
  as `Duration.Round()` and `Time.Truncate()` of Go, and require a `\RoundingMode` like the integer conversions,
  with no `truncate()` or `floor()` per direction. A wrong unit is a `\ValueError`. Rejected: returning
  the duration as it is for a unit of zero and below, as Go does: it hides a wrong config.
- Two functions, not one that takes `Duration|\DateTimeImmutable`: a union can be added later, not taken back,
  and the time has rules of its own (a unit of whole microseconds, the sign is the side of 1970).
- Both take the remainder by doubling the unit (`Internal\roundingStep()`), one path for 64-bit and 32-bit,
  durations and timestamps: int nanoseconds hold 2.1 seconds on 32-bit and a timestamp only until 2262 on 64-bit,
  and the quotient does not fit either. It costs 5 to 15 µs a call (up to 63 doublings). Not tried:
  a division of int nanoseconds first, where both fit. It needs a benchmark, and leaves the doubling
  to the 32-bit tests alone.

### Strings

- `parseDuration()` takes the grammar of `time.ParseDuration()` of Go and nothing else: one known contract,
  which reads what `formatDuration()` writes and the strings of Go services and Kubernetes. Hence `0` alone,
  units in any order and twice, `.5s`, and no spaces, no days (`1d`), no `sec` or `min`. A string
  that is not a duration throws `Time\TimeException`, as `fromIso8601DurationString()` does.
- A number of `parseDuration()` is a float converted by `fromFloat()`, so `parseDuration('1.5h')` is `hours(1.5)`,
  on 64-bit and 32-bit alike. Whatever `formatDuration()` writes comes back exactly: its hours and minutes
  are whole, its seconds are below 60. Rejected: arithmetic in decimal digits (the digits times 6 or 36,
  then the point moved), which was exact for a number of any length: 35 lines for numbers of more than
  15 significant digits that nobody writes.

### Points in time and clocks

- `durationBetween()`, `shiftTime()` and `roundTimeTo()` work on timestamps: elapsed time does not stretch
  with DST. So `roundTimeTo()` counts the multiples from 1970-01-01 00:00:00 UTC, and a window of `hours(24)`
  starts at the midnight of UTC in any time zone: a calendar day is not a duration.
- `shiftTime()` drops nanoseconds toward zero and takes the time first, as `multiply()` takes the duration first.
  Rejected: `after()`/`before()`, which break that order, `plus()`/`minus()`, two functions for one operation,
  and `fromInterval(\DateInterval)`, whose day is a calendar day.
- Accepted: on 32-bit a timestamp beyond ±2^53 seconds (year ±285 million) is a float off by a second
  in `durationBetween()` and `shiftTime()`: exact int64 in strings costs code for dates nobody has.
- Accepted: at the ends of int64 timestamps (year ±292 billion), on 64-bit too. Within 512 seconds of them
  `roundTimeTo()` may return a time that is not a multiple of the unit: a doubled multiple overflows to the float
  2^63, which is equal to any int that close to `PHP_INT_MAX`. `shiftTime()` beyond them returns a wrong time
  or fails with the error of `modify()`. Rejected: checks of the range for dates nobody has.
- `WallClock` and `FrozenClock` live here: a clock and a duration go together (`$clock->advance(sec(30))`),
  and `psr/clock` is an interface only. `FrozenClock` moves only when told: an implicit tick makes tests
  depend on the order of calls.

### The polyfill

- It is loaded eagerly by `polyfill/load.php` behind `class_exists()`. Rejected: a `classmap` of `polyfill/`,
  as the stubs of `symfony/polyfill-*`. At runtime 8.6 never reaches it, but PHPStan of a user on 8.6 finds
  `Time\Duration` in the classmap and takes the polyfill for the class of PHP (its private constructor,
  its methods), even behind `if (\PHP_VERSION_ID < 80600)`. A PSR-4 entry for `Time\` would also claim
  a namespace of PHP.
- The ISO 8601 parser replicates the scanner of timelib: `\DateInterval` drops the start, the end
  and the recurrences, which the class rejects with messages of their own.
- Rejected: matching the C class on data that `serialize()` never writes: mangled keys (accepted by 8.6),
  a reference between values (rejected by it), `__unserialize([])` on a live duration (a no-op there).
  It costs the unmangling, scopes and references of `object_properties_load()` for input nobody makes.
  What `serialize()` writes, wrong types and values, missing and extra keys behave as in 8.6.
- Rejected: matching the C class where a userland class cannot, `README.md` lists these differences instead.
  `null` for an int in coercive mode is a `TypeError` (8.6 deprecates it and takes 0): `?int` would take `null`
  in strict mode too, and a method does not know the mode of its caller. `var_dump()` and an `(array)` cast show
  `$signedSeconds` and `$signedNanoseconds`, which the comparison needs, and reflection sees `__serialize()`,
  which keeps them out of `serialize()`. Extra arguments are ignored (8.6 throws `ArgumentCountError`): this one
  can be matched by `func_num_args()` in every method, for a call that fails on 8.6 and in PHPStan anyway.

## Tests

- `DurationTest` pins the class with values from PHP 8.6 and passes against both: on 8.6 by `make test P=time`,
  against the polyfill by `PHP_VERSION=8.2 make test-alone P=time` (CI: 8.2 to 8.5). `outcome()` includes
  the messages of the previous exceptions.
- 32-bit: `PHP_VERSION=8.2 ARCH=386 make test-alone P=time` on the official image (ours has no i386 build),
  and without `PHP_VERSION` on 8.6, where `DurationFuzzTest` compares with the 32-bit class. Testo runs on 32-bit
  since 0.10.60 (before it ordered interceptors by numbers above 2^31, php-testo/testo#418), hence the constraint.
  A literal above 2^31 is a float on 32-bit, so the bounds of each platform have their own provider
  (`factoriesOf32Bits()`) with values from the class of that platform.
- Mutation testing runs on 8.6, without the polyfill: `IsoInterval` is in `src/` and tested directly.
- `DurationFuzzTest` runs on 8.6 only: it compares a renamed copy of the polyfill (`loadPolyfillCopy()`)
  with the class on random input, ISO strings built from the grammar and then broken. Boundaries random input
  does not hit (a product of exactly 2^64 - 1) go to `DurationTest`.
- Loose equality is checked by `Assert::equals()`: the fixer turns `==` into `===`, which can pass by accident
  since 8.6 caches the last duration.
