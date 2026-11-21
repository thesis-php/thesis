# Memory: ideas

This draft proposes a rule against reference cycles and a way to test it. If the project adopts the rule,
record it in `agents/memory.md`, list the topic in `AGENTS.md` and summarize the rule under "Classes and state"
in `agents/php.md`. The rule requires PHP to free an object when its last reference goes away, and a test
must verify that the object does not need the cycle collector.

## Rules to adopt

- An object graph has no reference cycles: every object is freed by its reference count the moment its last
  reference goes. The cycle collector runs only once its root buffer fills (10 000 roots by default), so until then
  a cycle holds all it reaches: buffers, sockets, suspended fibers. A destructor that closes a resource runs late,
  or at shutdown. In a long-running process this is a leak that grows with the load.
- Where cycles come from: a non-static closure (it captures `$this`) kept by the object itself or by what it owns,
  a callback of the loop that references its owner, a child that holds its parent, a fiber whose stack holds
  the fiber (`agents/fibers.md`).
- A link back is not a strong reference: a key (`Entry::$olderKey` in thesis/cache), a `\WeakReference`
  or a `\WeakMap`. A cycle that must exist while the object is in use is cut by an operation that always runs
  (`close()` in `finally`, as `Internal\Scope` of thesis/async), and a test covers every way out of it.
- Every class that holds other objects or callbacks has a test for each way its objects go: create, walk the path,
  drop the last reference, check the object is freed. By default with `Expect::notLeaks($object)` of Testo:
  it checks a `\WeakReference` after the test, when its locals are gone, and does not call `gc_collect_cycles()`,
  so a cycle fails it (checked: `$a->self = $a` fails, a plain object passes). Pass the inner objects too
  (a fiber, a scope, a stored value), not only the outer one.
- An inline `Assert::null($reference->get())` right after `unset()` instead, when the object must be freed
  at a given point (`ScopedValueTest`), and in a test that ends with an exception: its trace holds the arguments,
  so `Expect::notLeaks()` may report a leak that is not there (the PHPDoc of Testo says so, not checked).
- A test that allocates a lot (a loop, a fuzz) may start the collector itself and hide a cycle: it runs with
  `gc_disable()` and restores it in `finally` (`scopesGoAwayWithoutCycleCollector`).

## Decisions to adopt

- Rejected: relying on the cycle collector. When it frees a cycle is unknown, and each run scans the whole buffer:
  pauses that grow with the heap, on the hot path of whatever released the 10 000th root.
- Rejected: `gc_collect_cycles()` before the check in a test: it frees exactly the cycles the test is there to catch.

## Open questions

- An attribute in thesis/testo-plugin that disables the collector for the test and its expectations, instead of
  `gc_disable()` and `finally` by hand. Then `Expect::notLeaks()` is reliable in a test that allocates a lot.
- Which existing classes have no such test yet: a pass over the packages before the rule is adopted.
