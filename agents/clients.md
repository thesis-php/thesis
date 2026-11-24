# Long-lived clients

Read before designing a client that works in the background: a connection with a reader loop, heartbeats,
reconnects, deliveries to consumers (`amqp`, `nats`, `postgres`, ...). `amqp` is the reference implementation.

`thesis/async` has no unstructured spawn: every task belongs to a group that joins it. A background task started
in the scope of the first caller would die with that caller (a request, a message handler). So a client needs
an explicit owner for its background tasks.

## Rules

A client is a passive object with an explicit start:

```php
$client = new Client($config);  // no I/O
$client->start($group);        // handshake in the calling fiber, then $group->daemon(...) for the background work
```

- The constructor does no I/O, so a container can build the graph without opening connections.
- `start(Group $group)` returns once the client is ready (the handshake is done). Its background tasks run only
  as daemons of `$group`, never through bare `EventLoop::queue()`/`repeat()`. A failure to connect is thrown
  from `start()`, so an application fails at start, not at the first message. Readiness and the start order need
  no other contract: they are the return of `start()` and the order of the calls.
- A call before `start()` throws `LogicException` instead of waiting forever.
- `stop()` works while the daemons are still alive: a graceful close is a protocol exchange (`connection.close`
  and `close-ok`) that needs the reader. Stopping a consumer closes its source (`basic.cancel` closes the channel
  of deliveries, the consumer's `foreach` ends) instead of cancelling it.
- An application stops in this order: the disposers run (`stop()`, under `shield()` and `timeout()`), then the body
  of the group returns and the daemons that are left are cancelled. Cancelling the daemons first would leave
  `stop()` without a reader.
- Reconnects live inside the client's daemon, as a loop with a backoff (`Async\Backoff`).
- A library depends on `thesis/async` only, not on a container. A script wraps a client in `group()`,
  an application gets the group from its container.
- Clients implement `Thesis\Async\Service`. Guard against concurrent starts before the first wait.
  A failed start allows a retry. After a successful start, use a new instance to start again, even after `stop()`.
- A container starts a client with a hook on its declaration
  (`->onStart(static fn(Client $client, Group $group) => $client->start($group))`) or with autoconfiguration by type.
  What `thesis/dic` needs for it (a `Group` as an injectable dependency, a start hook, the order of the stop phases)
  is designed with `thesis/dic`.

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
