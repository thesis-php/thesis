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
$client->run($group);           // handshake in the calling fiber, then $group->daemon(...) for the background work
```

- The constructor does no I/O, so a container can build the graph without opening connections.
- `run(Group $group)` returns once the client is ready (the handshake is done). Its background tasks run only
  as daemons of `$group`, never through bare `EventLoop::queue()`/`repeat()`. A failure to connect is thrown
  from `run()`, so an application fails at start, not at the first message. Readiness and the start order need
  no other contract: they are the return of `run()` and the order of the calls.
- A call before `run()` throws `LogicException` instead of waiting forever.
- `close()` works while the daemons are still alive: a graceful close is a protocol exchange (`connection.close`
  and `close-ok`) that needs the reader. Stopping a consumer closes its source (`basic.cancel` closes the channel
  of deliveries, the consumer's `foreach` ends) instead of cancelling it.
- An application stops in this order: the disposers run (`close()`, under `shield()` and `timeout()`), then the body
  of the group returns and the daemons that are left are cancelled. Cancelling the daemons first would leave
  `close()` without a reader.
- Reconnects live inside the client's daemon, as a loop with a backoff (`Async\Backoff`).
- A library depends on `thesis/async` only, not on a container. A script wraps a client in `group()`,
  an application gets the group from its container.
- There is no shared interface (`Service`, `Runnable`). A container starts a client with a hook on its declaration
  (`->onStart(static fn(Client $client, Group $group) => $client->run($group))`) or with autoconfiguration by type.
  What `thesis/dic` needs for it (a `Group` as an injectable dependency, a start hook, the order of the stop phases)
  is designed with `thesis/dic`.

## Decisions

- A pool of connections lives in the package that needs it (`http-client`, `postgres`). A common pool
  (in `async` or `net`) is extracted only once two of them show what they share: a pool designed before its users
  guesses their needs (health checks, a connection tied to a transaction, keep-alive by host).
- Rejected: a lazy connect on the first call (`Sync\Once` of the old clients): the background tasks would start
  in the scope of the first caller.
- Rejected: a static factory `Client::connect($group, $config)`: construction would do I/O, so a container could not
  build its graph without connecting. It may come as sugar over `new` + `run()`.
- Rejected: a shared `Service` interface with `run(): void` until cancelled: it couples every library to a common
  package for one method, fixes the signature (a readiness signal would have to be added), and starts services
  by magic of the type instead of an explicit call.
- Rejected: `onStart`/`onStop` hook pairs as in uber/fx: they exist because goroutines are unstructured and share
  state between the two hooks. `run($group)` with daemons and `close()` express the same without it.
