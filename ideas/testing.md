# Testing: ideas

This backlog proposes tests for parsers, resource limits, state machines and failure scenarios with a real
broker. The proposals come from reading the code. Remove each proposal after completing it, and record any
invariants or rejected approaches in the package's `AGENTS.md`.

`BufferFuzzTest` already compares `io\Buffer` with `ReferenceBuffer`. The TLS tests cover name mismatches,
untrusted certificates and client certificates.


## amqp

### Publisher confirms against a model

A random sequence of publishes and server `ack`/`nack` (with and without `multiple`, tags out of range, repeats,
gaps) is applied to a real `Channel` over `FakePeer` and to a trivial reference model (a set of unconfirmed tags).
Every `Publish` is settled exactly once and with the outcome of the model.

### Fuzzing the state machine of a channel

`FakePeer` sends random well-formed frames in a wrong order: a body without a header, a header without a deliver,
`channel.close` in the middle of a content, `basic.cancel` while a consumer is iterated, frames on a channel
that is not open. The invariant: the connection either goes on or closes with `ProtocolError`, every wait ends,
no fiber leaks (a `\WeakReference` to it is null, as in the tests of async).

### Resource limits (a decision to make)

- `Config::$frameMax` is `0` by default. If the server also sends `0`, `negotiate()` gives no limit, and
  `readFrame()` fills the buffer up to the size from the frame header: up to 4 GB.
- `bodySize` has no limit: the body grows while the server sends it.

Not a problem with RabbitMQ, but a hostile server or a MITM without TLS exhausts the memory of the process.
Options: a `maxBodySize` in `Config`, a hard frame limit when both sides send `0`, or a decision
"the server is trusted" in `packages/amqp/AGENTS.md`.

### Chaos with a real RabbitMQ

toxiproxy in `compose.yaml` in front of RabbitMQ:

- `slicer` cuts the stream into 1-byte pieces: partial frames read for real,
- `latency` and `timeout`: heartbeats and timeouts,
- `reset_peer` in the middle of a batch: the reconnect, and every `Publish` gets an outcome,
- `rabbitmqctl close_connection` and a memory alarm: `connection.blocked`/`connection.unblocked`.

## net

- `Dns\Message::parse()`: fuzzing with the invariant "only `Response`, `null` or `DnsFailed`". Cases worth seeding:
  a compression pointer at the very end, `answers = 65535` in a short response, an `rdlength` past the end.
- `Dns\SystemConfig`: fuzzing on garbage `resolv.conf` and hosts files, the parser never fails on a strange line.
- DNS spoofing: the id is random (`random_int()`). Pin by tests, if `DnsResolverTest` does not have them yet,
  that a response with the right id but another question is ignored and that a truncated one falls back to TCP.

## Secrets

`Config::$password` is a `#[\SensitiveParameter]` in the arguments but a plain `public string` in the object:
`var_dump($config)` and loggers that dump objects show it. `__debugInfo()` and a test: the password of a URI
appears in no exception message and in no dump of `Config` or `ConnectionFailed`.

## Mutation testing

`make infect` runs on every package but `amqp` and `phpstan-extension` (the matrix of `check.yaml`).
`amqp` is next: the protocol and the state of a channel, where the tests may run the code and check nothing.

## Order

1. Publisher confirms against a model.
2. The rest as time permits.
