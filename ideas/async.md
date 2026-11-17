# Async: ideas

Models of concurrency that thesis/async may borrow from, left from the prototypes written before it.
The first prototype, ambient cancel scopes with a nursery (Trio, Kotlin `coroutineScope`), became thesis/async:
cancellation is a property of the task tree, one `suspend()` for every wait, level-triggered `Cancelled`.
A draft, not a decision: what is adopted goes to the "Decisions" of `packages/async/AGENTS.md`, what is rejected
too, and it is deleted from here. The prototypes are in git, removed in the commit that added this file.

## Lazy tasks (ZIO, Effect-TS, cats-effect)

A `Task<T>` is a description of a computation, not a running one: it can be run again, so retry, repeat and
hedged requests are combinators, and so are timeout and fallback. Combinators are free functions composed by `|>`
with partial application of PHP 8.6 (`retry($schedule, ?)`), so a package adds its own without inheritance.
A retry schedule is a pure value (`iterable<Duration>`), tested without a clock.

The price is colour again: `User` and `Task<User>` are different types, exactly what fibers got rid of.
Worth it at the boundaries only (a client of an outside service, a retry policy), as a thin layer over `group()`,
`timeout()` and `shield()`. Overlaps with "Resilience" of `roadmap.md`.

## Operations as values and `select()` (Concurrent ML, Go)

Every wait is an `Op<T>` (`$channel->receiving()`, `$channel->sending($value)`, `after($delay)`), `select()` waits
for several and commits exactly one, the losers are disarmed atomically. Scopes answer when a subtree dies,
`select()` answers which of several events comes first: "a batch by size or by time" is awkward with `timeout()`
and direct with `select()`.

- An `Op` has `poll()` (ready now?) and `arm($waker)` returning a disarm. `select()` polls the branches in random
  order (fairness), then arms all on one waker, then disarms the losers. One thread makes the commit trivial:
  no two-phase CAS as in CML.
- Named branches: `select(job: ..., stop: ...)` gives string keys, matched by `match`.
- `select()` stands on `suspend()`, so the cancellation of the scope is an implicit branch, no `done` channel.
- A value taken from a channel wins over a cancellation that comes at the same time, or messages are lost.

## Effects and handlers (OCaml 5, Koka)

A fiber suspends with a description of what it needs (`perform(new Sleep($duration))`), a handler up the stack
decides what to do: the timers of the loop in production, virtual time and a seeded scheduler in tests
(deterministic simulation, `roadmap.md`), a journal of results for durable execution (Temporal, Restate).

- Found by running it: a nested fiber per handler, forwarding an unhandled effect by `Fiber::suspend()`,
  is incompatible with Revolt. A suspension of Revolt inside returns to our handler with `null`, and Revolt
  resumes the nested fiber directly later, past the handler.
- So the scheme is flat: one task, one fiber, a stack of handlers in fiber-local state. A handler that answers
  at once (`Now`, `Random`, `Log`) is a function call, one that waits suspends to the scheduler.
- A continuation is one-shot: no backtracking.

The price: it replaces the bottom layer, Revolt libraries work only through an adapter. What to take without it:
time, randomness and I/O behind an interface of the runtime that a test replaces (as `virtualTime()` does
for time).

## Transparent futures (lazy proxies of PHP 8.4)

A task returns a `User` that is a lazy proxy (`ReflectionClass::newLazyProxy()`), its initializer awaits the task.
Checked: an initializer may suspend the fiber. The task stays in a group, so nothing leaks. With batching
(Haxl, DataLoader) `load($id)` only remembers the id, the first access runs one query for all: N+1 goes away
without changing the code that reads.

Limits: objects of user classes only (no scalars, arrays, enums, interfaces), an error comes out at the first
access to a property (in a template), a proxy is not identical (`===`) to the real object. Sugar for a layer
of reads, not a model.

## Actors and supervision (Erlang/OTP)

For what lives long and has state (a connection, a pool, a subscription): a fiber owns the state and reads
messages one by one, no locks inside. An actor is a `Task` in a group with an `Async\Channel` as its mailbox,
a supervisor is a group that restarts instead of failing fast, an exit signal is the cancellation of its scope.

- A typed `Ref<M>` and a request `Request<R>`, so `call()` returns `R`.
- Messages are `readonly` objects: no isolation of the heap in PHP, only a convention.
- The mailbox is bounded, its overflow is an explicit policy (wait, drop, fail the sender).
- A restart intensity (at most N in a period), exceeding it fails the supervisor.

The price: `call()` is slower and longer than a call, two actors calling each other synchronously deadlock.
Owners of resources only, not every function. Compare with the clients of `agents/clients.md`, which already
own their background work.

## An event loop on `Io\Poll` of PHP 8.6

`Io\Poll` gives epoll and kqueue without an extension, instead of `stream_select()` (O(n) a tick,
`FD_SETSIZE` = 1024). The default way is a driver of Revolt on it, so everything gets it. A loop of our own pays
only with effects, where the scheduler must see every suspension. Not run: written from the RFC.

- `Io\Poll` has no timers or signals: a timer queue of our own, the wait until the nearest timer;
  signals by `pcntl_async_signals()` and a self-pipe.
- Almost every timer of `timeout()` is cancelled before it fires, so cancelling is what to make cheap:
  lazy deletion or a hierarchical timer wheel.
- The ready queue is processed by batches: what tasks scheduled in a tick goes to the next one,
  or two ping-pong tasks starve I/O and timers.
