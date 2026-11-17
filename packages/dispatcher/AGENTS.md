# thesis/dispatcher

A synchronous event dispatcher that routes by the type of a listener's parameter. The API is in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- The hot path is `dispatch()`: one lookup of `$listenersByClass[$event::class]` and the calls. Everything else
  is done once: the types are parsed and checked in the constructor (`Internal\Listener::of()`), the listeners
  of a class are found on its first event or `listens()`. Never reflect or match types per event.
- The table is filled lazily, not in the constructor: the classes of the events are not known in advance,
  a listener of an interface gets the events of classes that are loaded later (anonymous ones too).
- A type is kept in the disjunctive normal form (`(A&B)|C` is `[[A, B], [C]]`, `null` for every event), so a class,
  a union, an intersection and a DNF go through one match, and a listener is added once per class whatever
  number of its types matches.
- The order is the order of the constructor. A dispatcher is immutable, so the table is never invalidated
  and a listener added during `dispatch()` is not a case. For the same reason a listener may dispatch:
  it is a plain nested call, its listeners run before the next listener of the outer event.
- Every listener is called with the event and the dispatcher, `$listener($event, $this)`: nothing about
  the second parameter is reflected or decided per listener. The parameters after the event are a contract
  of types (`callable(never, self): void` of the constructor and of `Subscriber::listeners()`, `php.md`),
  not of the constructor's checks.
- `dispatch()` does not care whether a listener suspends the fiber: `resolve()` does not suspend, so concurrent
  dispatches from other fibers see a consistent table. A ban on suspending belongs to the user of the dispatcher
  (the trace of thesis/async calls its listeners inside a wait), not here.
- An error names the listener or its parameter through thesis/formatter, never by hand.
- The constructor rejects every listener that would not do its work when called, not only a wrong parameter:
  a generator is rejected, its body would never run. Trap: `?object` is a `ReflectionNamedType` named `object`,
  so a check by name alone takes it for every event; only `mixed` allows `null` and still means every event.
- Trap, open: a closure written right inside `new Dispatcher([...])` gets its parameter typed `never` by PHPStan
  from `callable(never, self): void` of the type of the constructor: `static function (Foo $event)` sees `$event`
  as `never`, and every use of it is reported (`cast.useless`). A closure assigned to a variable first keeps
  its declared type, as the tests of thesis/async and thesis/async-trace do, and so does one written
  in `Subscriber::listeners()`. Not fixed yet. A fix that PHPStan accepts: a method that takes one listener
  with a template, `@template T of object` and `callable(T, self): void`. The closure keeps its type and
  the parameters after the event are still checked, which the type of an iterable cannot do: a template is one
  for the whole call. Rejected: `callable-string|callable-array|object`, a type without a signature. The closure
  keeps its type, but `checkMissingCallableSignature` reports the type itself, and PHPStan reads `callable-array`
  as `callable(): mixed`, which rejects `[$object, 'method']` with a parameter.
- Trap: an internal function that declares one parameter (`$queue->push(...)`) passes PHPStan as a listener
  and throws `ArgumentCountError` on its first event. PHP ignores an argument that a user function does not declare,
  an internal one rejects it.

## Decisions

- The class is `Thesis\Dispatcher`, as in the older package: `Thesis\Dispatcher\Dispatcher` stutters.
  The manifest sets `rootEntry: true`, `Internal\` and the fixtures of the tests are in `Thesis\Dispatcher\`.

- Rejected: PSR-14 in the core. `StoppableEventInterface` makes the order of listeners part of their meaning and
  costs a check per listener, `dispatch(): object` serves mutable events, and nobody needs a foreign provider
  or dispatcher yet. A bridge is a separate package over `listens()` and the same resolution when someone does.
- Rejected: calling them hooks. A hook is an extension point declared by its caller, with a fixed signature,
  often able to change the outcome (return a value, cancel). Here an event of any class goes to the listeners
  of its type and nothing comes back: an observer, named as in PSR-14, Symfony and Laravel. A package that uses
  the dispatcher may call its points hooks in its own docs, the objects stay events. A callback that changes
  the outcome is another mechanism, not a rename of this one.
- The constructor takes an `iterable` of listeners, not `callable ...$listeners`: no parameter can follow
  a variadic one, so an option added later would go before the listeners and break every call.
  An iterable, not an array: containers give their tagged services as one.
- A listener gets the dispatcher as its second argument: a listener that derives events of its own (a tool
  of thesis/async-trace reports what it has found) needs the dispatcher that holds it, and an immutable dispatcher
  cannot be given to the constructor of its own listener. Rejected: passing it only to a listener whose second
  parameter is typed `Thesis\Dispatcher` (reflection per listener and a wrapper closure in the table that holds
  the dispatcher, a cycle), a constructor that takes a factory of listeners `fn(Dispatcher $self) => [...]`
  (`$this` leaves the constructor half-built), a listener that returns events (a check per call, and
  "nothing comes back" below).
- Rejected: a queue for the events dispatched by a listener, delivered after the listeners of the outer event.
  `dispatch()` would return before the delivery inside a listener and after it outside, what a listener of the inner
  event throws would leave the outer `dispatch()`, and a listener may suspend the fiber, so "the outer dispatch"
  needs a flag per fiber on the hot path. Rejected: a limit of the depth, a counter and a `try` per event against
  a mistake that fails on its first run.
- Rejected: priorities, until someone needs them. The order of a listener that dispatches is the one case
  where they would help ("it goes after those that take both events"), for now it is a line of `README.md`.
  A number has to be agreed between packages and makes the order a part of the meaning of a listener.
  If the order becomes a trap, the candidate is two phases without numbers: the listeners that dispatch
  are called after the rest.
- Rejected: not caching `listens()` of a class that is not declared yet. Its parameter is a `class-string`,
  and `is_a()` autoloads it, so only a class declared later by `eval()` or a condition is cached wrong:
  a check of `class_exists()` per miss for a case the type already excludes.
- Rejected: collecting the exceptions of all the listeners: the first one is thrown, the rest are not called,
  so the failure points at the listener that failed.
- Rejected: stripping `null` from `?A`: the dispatcher never passes `null`, a nullable type is a mistake
  of the listener. Rejected: rejecting intersections and DNF: in the normal form they cost nothing more.
- Several listeners of one object are given by `Subscriber::listeners()`, and the object goes to the constructor
  as it is, `new Dispatcher([$tool, $listener])`: where a dispatcher is built nobody has to know whether a listener
  is one callable or several, and a class may change from one to the other without breaking its users.
  A subscriber is never called itself, an invokable one too. Its listeners are private methods given by `yield`:
  PHPStan checks each by the return type of `listeners()` and reports one that is not given as `method.unused`,
  so the list apart from the methods is not a trap, and the listeners are not the public API of the class.
  No listeners is not an error: a subscriber may listen to nothing by its settings.
- Rejected: the methods marked `#[Listener]` of 0.3.0, found by reflection. A mark fits a container that scans
  classes, not an object given by hand: it needed a rule of `phpstan-extension` for what PHP does not check
  (public, not abstract, the dispatcher as the second parameter), and a forgotten mark showed only as a listener
  that never runs. Rejected with it, the ways to give a marked object to the dispatcher:
  `[...listenersOf(new BlockingDetector()), $print]` (it shows how a class is written wherever it is used,
  and a tool that reports always stands next to a listener of its reports), a constructor that takes any `object`
  and looks for the marks (`callable|object` takes a closure and an invokable object of any signature, PHPStan
  stops checking them), an empty marker interface next to the marks (two declarations that can disagree).
- Rejected: `withListener()` and `withSubscriber()` instead of the list: the types are precise, but where
  a dispatcher is built one has to know again which of the two a class is. Rejected: an invokable object
  with a `match` over the events (it hides what it listens to), a list of `$object->onX(...)` where the dispatcher
  is built (far from the methods).
- Rejected: `subscribe()` and unsubscribing of the older 0.2 (a mutable table, listeners added during a dispatch
  called in the same dispatch, only final classes). A listener that must stop is a check in the listener,
  a set that changes is a new dispatcher.
