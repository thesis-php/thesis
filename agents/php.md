# PHP code

Read before writing or reviewing PHP code in any package or tool. What `make fixer` and `make rector` enforce
is not here: the tools fix the code anyway.

## Rules

### Form

- Lines are at most 120 characters, comments and tests included. The only exception is a line that cannot be wrapped:
  a single string literal (an exception message, an expected error) or a URL.
- A call stays on one line while it fits in 120 characters, named arguments or not:
  `self::bytes($random, min: 0, max: 40)`. Otherwise its arguments go one per line with a trailing comma,
  and every argument is named, so the names form a column. The exception is a call whose arguments go
  to a variadic parameter (`\sprintf()`, `...$args`): PHP cannot name them.
- Don't neglect named arguments where they help reading: a flag or a bare literal (`strict: true`, `limit: 3`),
  neighbours that are easy to swap (`actual:`/`expected:`), a call of our own code with many parameters,
  a data object built field by field (a DTO, a manifest), a rarely used function whose parameters nobody remembers
  (`proc_open()`, `stream_socket_pair()`). Where the call already reads well, skip them:
  `substr($bytes, $offset, $length)`.
- `_` in a numeric literal groups a magnitude, it is placed by hand (the fixer rule put it everywhere: `2_026`).
  A decimal integer of 5 digits and more is grouped by thousands (`10_000`, `65_536`, `1_048_576`), a shorter one
  is not (`1000`, `3600`, `8192`). A hex literal of up to 4 digits has no `_` (`0xFFFF`, `0x8000`), a longer one
  is grouped by bytes (`0x7F_FF_FF_FF`, `0x01_02_03_04`). Never in a number that names something rather than
  counts (a year, a port, a protocol code: `$port <= 65535`) and never in a fraction.
- The pipe operator (`|>`) is for an explicit pipeline of several transformations of one value, where it replaces
  deep nesting or a chain of temporary variables. A call or two of nesting stays a plain call: `f(g($x))`,
  not `$x |> g(...) |> f(...)`.
- A `match` over a closed set (the cases of an enum, the types of a union, a `@phpstan-sealed` interface) names
  every member, the last one too (`$event instanceof Ended`), and has no `default`: a member added later fails
  `match.unhandled` in PHPStan instead of falling silently into `default`. `default` is for an open set:
  a code of a protocol, a string, an implementation of an open interface, `mixed`.
- No return types on closures and arrow functions: `static fn() => new X()`, not `static fn(): X => new X()`.
  The exception is a closure whose type PHPStan cannot infer through a generic parameter without it
  (nested `callable(): T`, `ScopedValue::with()` in a `group()` in the tests of thesis/async).
- A path to a parent directory is `dirname(__DIR__)` or `dirname(__DIR__, 2)`, never `__DIR__.'/..'`:
  a path without `..` reads well in errors and can be compared as a string with other paths.
- Prefer language features and standard library APIs of the target PHP over older equivalents and userland packages:
  first-class callables (`f(...)`) instead of wrapper closures, a variadic parameter (`...$args`) instead of
  `func_get_args()`, `Uri\WhatWg\Url` instead of `parse_url()` or a URL library.
- A byte of a string is changed by `substr_replace()`, not by `$s[$i] = $s[$i] ^ $mask`: `long_to_shorthand_operator`
  of the fixer makes it `^=`, which fails at runtime on a string offset. Delete this rule once the fixer leaves
  offsets alone (PHP-CS-Fixer/PHP-CS-Fixer#9760).

### Reading

- Code is read at a glance, not decoded. If a reader has to work out what an expression means (rearrange
  an inequality, trace a bit trick, recount a bound), rewrite it to say what it means: a plainer form,
  a named variable or a function. Obvious and a line longer beats clever and short. Only a benchmark
  keeps a trick (the hot path of `Buffer`), and then a comment on that line says what it computes.
- A comment explains what the code cannot: a non-obvious reason, a constraint, a trap. If it retells the code,
  rename the variable, method or class instead and delete the comment. PHPDoc types are not comments,
  they stay.
- No one-letter names except a loop index (`$i`) and the operands of a comparator (`$a`, `$b`):
  `$n`, `$o`, `$v` make the reader look up what they hold.
- Where the PHP standard library has a name for the same thing, take it: a number of bytes is `$length`
  (`substr()`, `fread()`), a position is `$offset`, the data is `$bytes`. In a write loop the bytes one call
  has written are `$sent`, all written so far `$written`.

### API

- Every top-level symbol (class, interface, trait, enum, function, constant) in `src/` is marked either `@api`
  or `@internal` in its PHPDoc, never both. Internal symbols live in the `Internal` sub-namespace of the package
  (`Thesis\Async\Internal\...`) and nothing else does, so the namespace alone tells a user what is safe to depend on.
  Tests are not marked: they are not part of the API.
- Everything of a package is in `Thesis\<Name>\`. The one exception is a package that *is* its entry point:
  that symbol, named after the package, may live in the root namespace (`Thesis\Dispatcher`,
  `Thesis\exceptionally()`). Nothing else goes to the root but its test (`Thesis\DispatcherTest`),
  not `Internal`, not test helpers or fixtures: `Thesis\` is shared by all the packages, only the name
  of a package is guaranteed to be free there. A package with several peers (`async`, `io`, `time`) keeps them
  in its namespace. How the manifest declares it is in `manifests.md`.
- PHPStan (`level: max`, strict rules) is part of the contract. A constraint expressed in a PHPDoc type
  (`non-empty-array`, `positive-int`, ...) needs no runtime guard and no test for violating it.
  Don't flag "PHPDoc isn't enforced at runtime" in reviews: tighten the type instead.
- A public API accepts `callable` with a signature (`callable(T): void`), not `Closure`: users may pass
  invokable objects, `[$object, 'method']` and the like.
- A duration in a public API (a timeout, a delay, a TTL, an interval) is `Time\Duration`, created and converted
  with the functions of `thesis/time` (`sec(5)`, `ms(250)`, `toSecFloat()`). Never a bare `int`/`float` of seconds
  or milliseconds, and never `float|Duration`: the unit of a number is only a convention, and a union can be widened
  later but not narrowed. Inside, time is `int` nanoseconds: exact like `Duration` (`ns()`/`toNs()` convert
  without a loss) and as cheap as a float, while a `Duration` object costs an allocation per operation.
  Convert at the boundary to what the underlying API takes (Revolt: float seconds, `$ns / 1e9`).

### Classes and state

- `readonly` wherever possible: `final readonly class` for immutable classes, `readonly` on individual properties
  for partially mutable ones.
- Named typed properties instead of id-indexed arrays (`$this->values[0]`).
  Use an array only for genuinely dynamic sets.
- No setters: writing a value is assigning a property. A property anyone may change with nothing to check
  is plain `public` (`$clock->now = $time`, not `$clock->set($time)`), one that changes only inside is
  `public private(set)` instead of a getter, one that never changes is `readonly`. A `set` hook is only
  for checking or normalizing on write. A method is for an operation with a meaning of its own
  (`advance($duration)`), for writing that waits (it may suspend the fiber) or returns something,
  and where an interface requires it.
- A copy with some properties changed is `clone($object, ['name' => $value])`, not `clone` and assignments,
  not a constructor call that repeats every property, not a `withX()` method. A `readonly` property is implicitly
  `protected(set)`, so for `clone()` from outside the class declare it `public public(set)`: it stays readonly
  (written once per object), only the copy may be made anywhere (`Thesis\Amqp\Message`).
  The array costs an allocation: a hot path keeps `clone` and assignments if a benchmark says so (`Buffer::slice()`).
- A method that doesn't use `$this` is `static` and is called via `self::`, in hand-written and generated code alike.
  Exceptions only where the language forbids it: interface implementations and overrides of instance methods.
- No static classes that pass complex state between their methods (the same arguments to every method,
  accumulators by reference). Write an object-function instead: a static entry point that creates a private
  instance, keeps the state in its properties and returns the result, e.g. `Validator::validate($monorepo)`.
- State that only one function needs (a memoized value, a cache, a lazily created instance) is a `static` variable
  inside that function, not a static property: it sits next to the only code that uses it, and since PHP 8.3
  it is initialized with any expression. A static property is only for state shared by several methods.
- Static constructors go first in a class, the destructor goes last. The constructor body uses its parameters,
  not the promoted properties (`$x`, not `$this->x`) where possible.

### Tests

- Write the failing test first, then the fix.
- `make infect P=<name>` kills every mutant of a package in the matrix of the job `infect` of `check.yaml`.
  A surviving mutant gets a test. A mutant that shows code to be redundant gets the code simplified. Only one
  that no input tells from the code (most often it only makes the code slower) is skipped by a comment
  above the statement it mutates: the reason, then `// @infection-ignore <Mutator>, ...`. An annotation covers
  everything inside the statement, so above a loop or an `if` with more in it the comment goes before
  the expression: `/* @infection-ignore LessThan */ $shift < 64`. A misspelt mutator skips nothing,
  Infection only warns, so the mutant still fails the run.
- No traits for test helpers: plain functions (in `tests/helpers.php`) or a base class.
- A closure that writes a variable of the test captures it by reference, and so does every closure on its way:
  `static function () use (&$called)`, never `static fn()` around it. An arrow function captures by value,
  the write is lost, and an assertion like `Assert::false($called)` passes on a broken library.

## Decisions

- Rejected: skipping mutants in the manifests (`InfectionSkip`, generated into `tools/infection.json5`).
  Infection could skip only a whole method or a line found by its text in every class, and `sync` had to find
  the method and the line to fail on a stale skip. An annotation of Infection 0.35.10 names the mutators
  of one statement, sits next to the reason, and leaves with the code.
- Rejected: a mechanical rule for named arguments like "name every argument of a call with more than two",
  it is noise around the standard library.
- Rejected: `reportAlwaysTrueInLastCondition: true` of PHPStan (it came with the scaffold): it reports the last arm
  of an exhaustive `match` as always true, so it forced the `default` that hides a new member of a closed set.
  Turned off, PHPStan still reports a missing member and an always true arm that is not the last.
- Rejected: a Rector or PHP CS Fixer rule for the named arguments of a multi-line call and for the order
  of static constructors. Both are fragile (the parameters of a method are known only through reflection,
  a static constructor is not always one that returns `self`) and cost much code to write and maintain.
  The reviews catch them.
