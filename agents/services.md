# Background services

Read before designing a service that owns background work in a group.

## Service lifecycle

- Implement `Thesis\Async\Service`. Keep construction free of I/O so a container can build the graph before startup.
- When `start(Group $group)` returns, callers can use the service. The caller receives startup failures
  and controls startup order. Guard against concurrent starts before the first wait.
- The group owns the background tasks. A task started in the first caller's scope would die with that caller
  (a request or message handler).
- Call `stop()` while the group can run the tasks needed for shutdown. Run disposers under `shield()` and
  `timeout()` before returning from the group body. A successful `stop()` joins the service’s background work.
  Call it from outside that work before stopping its dependencies.
- A script wraps the service in `group()`. An application gets the group from its container.
  The library depends on `thesis/async` for this lifecycle.
- A container starts a service with a hook on its declaration
  (`->onStart(static fn(Client $client, Group $group) => $client->start($group))`) or with autoconfiguration by type.
  What `thesis/dic` needs for it (a `Group` as an injectable dependency, a start hook, the order of the stop phases)
  is designed with `thesis/dic`.

## Clients with background connections

AMQP is the reference client for reader loops, heartbeats, reconnects and deliveries to consumers.

```php
$client = new Client($config);  // no I/O
$client->start($group);        // handshake in the calling fiber, then $group->daemon(...) for the background work
```

- Complete the handshake in the calling fiber. A connection failure reaches the application at startup.
  Run background work as group daemons instead of bare `EventLoop::queue()` or `repeat()` callbacks.
- A call before `start()` throws `LogicException` instead of waiting forever.
- `stop()` works while the daemons are still alive: a graceful close is a protocol exchange (`connection.close`
  and `close-ok`) that needs the reader. Stopping a consumer closes its source (`basic.cancel` closes the channel
  of deliveries, the consumer's `foreach` ends) instead of cancelling it.
- Reconnects live inside the client's daemon, as a loop with a backoff (`Async\Backoff`).
- A failed start allows a retry. After a successful start, use a new instance to start again, even after `stop()`.

## Decisions

- A pool of connections lives in the package that needs it (`http-client`, `postgres`). A common pool
  (in `async` or `net`) is extracted only once two of them show what they share: a pool designed before its users
  guesses their needs (health checks, a connection tied to a transaction, keep-alive by host).
- Rejected: a lazy connect on the first call (`Sync\Once` of the old clients): the background tasks would start
  in the scope of the first caller.
- Rejected: a static factory `Client::connect($group, $config)`: construction would do I/O, so a container could not
  build its graph without connecting. It may come as sugar over `new` + `start()`.
- Rejected: a blocking `run(): void` that lasts until cancellation. It needs a separate readiness signal.
  Implementing `Service` describes how to start a service. The application chooses which services to start.
- Rejected: `onStart`/`onStop` hook pairs as in uber/fx: they exist because goroutines are unstructured and share
  state between the two hooks. `start($group)` with daemons and `stop()` express the same without it.
