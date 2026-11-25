# Coordination and worker primitives

- `race()` is `some(1, ...)`. `some()` decides once: after `$count` successes failures are dropped, after more than
  `count($tasks) - $count` failures successes are dropped and every later failure goes to `suppressed()` of the first.
  `allSettled()` never records `Cancelled` as an outcome: it cancels nothing, so a cancelled task means the caller is
  cancelled.
- `Channel` hands values over like `LocalSemaphore` hands permits: a value goes to a waiting receiver, or is taken
  from a waiting sender, only if that waiter's `resume()` returns `true`; otherwise it goes to the next one or to the
  buffer. So a `send()` that throws `Cancelled` has not delivered, and a `receive()` that throws `Cancelled` has not
  taken anything. A rendezvous channel has no buffer to allocate. A local semaphore creates its wait queue
  when a caller first has to wait for a permit and drops it when a permit returns to the pool.
  Waiting receivers imply an empty buffer; waiting senders imply a full one (or a rendezvous).
- `each()` and `map()` run on `Internal\Workers`: at most `concurrency` tasks, each takes the next item under
  `synchronized()` (a generator may suspend, and another worker must not resume it meanwhile); an array cannot suspend,
  so it is taken without the lock. A single worker needs no lock for any iterable.
  An item is pulled when it is taken, never ahead: `next()` before `valid()`, not after `current()`. Taking checks cancellation first (after getting the lock), so nothing is pulled after a failure.
  A new worker is started only when the item just taken leaves no worker free.
  The callable gets the value only (`$fn($value)`, so an internal function fits); only `map()` uses the key, for its
  result. A repeated key in a generator takes the last input's result. Each invocation writes its own cell, so
  an earlier invocation that finishes later cannot overwrite it. `each()` keeps no results, so its memory does not grow with the items, and its keys need not be array keys.
- `Once::await()` runs the callback in the first caller's scope. All callers share its result or ordinary failure.
  Only `Cancelled` escaping the callback can reopen a `Once` constructed with `retryOnCancel: true`.
  A waiter cancelled in its own scope leaves the attempt alone. Work owned by a group (such as a shared DNS query)
  belongs in a task of that group. Rejected: starting the callback in a background task from the constructor.
- A lock held for one method is `synchronized($mutex, $this->method(...))`, not `acquire()` with a release
  in `finally`: `Lock` releases itself in its destructor too, so mutation testing shows the `finally`
  as code no test tells apart (the rate limiters).
