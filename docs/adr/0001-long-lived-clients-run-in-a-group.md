# 1. Long-lived clients run in a group they are given

Status: accepted

## Context

A protocol client (`amqp`, `nats`, `pgmq`, ...) holds a connection with work in the background: a reader loop,
heartbeats, reconnects, deliveries to consumers. The old amphp-based clients start it with `EventLoop::queue()`,
`EventLoop::repeat()` and `async()`, and connect lazily on the first call from whatever fiber comes first.

`thesis/async` has no unstructured spawn: every task belongs to a group that joins it. A background task started
in the scope of the first caller would die with that caller (a request, a message handler). So a client needs
an owner for its background tasks, and the owner must be explicit.

The same clients will be services of an application assembled by a future `thesis/dic`, which already has
`Dic::run()`, lazy fiber-safe singletons, scopes and disposers run in reverse dependency order.

## Decision

A client is a passive object with an explicit start:

```php
$client = new Client($config);  // no I/O
$client->run($group);           // handshake in the calling fiber, then $group->daemon(...) for the background work
```

1. The constructor does no I/O, so a container can build the graph without opening connections.
2. `run(Group $group)` returns once the client is ready (the handshake is done). Its background tasks run only
   as daemons of `$group`, never through bare `EventLoop::queue()`/`repeat()`. A failure to connect is thrown
   from `run()`, so an application fails at start, not at the first message.
3. A call before `run()` throws `LogicException` instead of waiting forever.
4. `close()` works while the daemons are still alive: a graceful close is a protocol exchange (`connection.close`
   and `close-ok`) that needs the reader. Stopping a consumer closes its source (`basic.cancel` closes the channel
   of deliveries, the consumer's `foreach` ends) instead of cancelling it.

An application stops in this order: the disposers run (`close()`, under `shield()` and `timeout()`), then the body
of the group returns and the daemons that are left are cancelled. Cancelling the daemons first would leave
`close()` without a reader.

There is no shared interface (`Service`, `Runnable`) for such clients. A container starts a client with a hook
on its declaration (`->onStart(static fn(Client $client, Group $group) => $client->run($group))`) or with
autoconfiguration by type, configured by the application or a bridge module. What `thesis/dic` needs for this
(a `Group` as an injectable dependency: the container's group for singletons, the scope's group for scoped services;
a start hook; the order of the stop phases) is designed with `thesis/dic`, not here.

## Consequences

- No client can be used without an owner: a script wraps it in `group()`, an application gets the group from
  its container.
- Readiness and the start order need no extra contract: they are the return of `run()` and the order of the calls.
- Reconnects live inside the client's daemon, as a loop with a backoff.
- A library depends on `thesis/async` only, not on a container.

## Rejected

- **Lazy connect on the first call** (`Sync\Once` in the old clients): the background tasks would start in the scope
  of the first caller.
- **A static factory `Client::connect($group, $config)`**: construction would do I/O, so a container could not build
  its graph without connecting. It may come as sugar over `new` + `run()`.
- **A shared `Service` interface with `run(): void` until cancelled**: it couples every library to a common package
  for one method, fixes the signature (a readiness signal would have to be added), and starts services by magic
  of the type instead of an explicit call.
- **`onStart`/`onStop` hook pairs as in uber/fx**: they exist because goroutines are unstructured and share state
  between the two hooks. `run($group)` with daemons and `close()` express the same without it.
