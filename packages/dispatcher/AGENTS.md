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
  and a listener added during `dispatch()` is not a case.
- `dispatch()` does not care whether a listener suspends the fiber: `resolve()` does not suspend, so concurrent
  dispatches from other fibers see a consistent table. A ban on suspending belongs to the user of the dispatcher
  (the trace of thesis/async calls its listeners inside a wait), not here.
- An error names the listener or its parameter through thesis/formatter, never by hand.

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
- Rejected: priorities, until someone needs them.
- Rejected: collecting the exceptions of all the listeners: the first one is thrown, the rest are not called,
  so the failure points at the listener that failed.
- Rejected: stripping `null` from `?A`: the dispatcher never passes `null`, a nullable type is a mistake
  of the listener. Rejected: rejecting intersections and DNF: in the normal form they cost nothing more.
- Rejected: `subscribe()` and unsubscribing of the older 0.2 (a mutable table, listeners added during a dispatch
  called in the same dispatch, only final classes). A listener that must stop is a check in the listener,
  a set that changes is a new dispatcher.
