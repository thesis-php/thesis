# Testing: ideas

Where the existing code can be tested better: parsers of what a peer sends, resource limits, state machines,
chaos with a real broker. A backlog, not a decision: an idea that is done is deleted from here, a finding
(an invariant, a rejected approach) goes to the `AGENTS.md` of its package. Found by reading the code, not run yet.

Already covered well: `io\Buffer` by a differential fuzz test against `ReferenceBuffer` (`BufferFuzzTest`),
TLS by tests of the failures (another name, an untrusted certificate, client certificates).

## amqp

### Fuzzing the frame decoder

The invariant: any bytes give a frame or `ProtocolError`/`UnexpectedEof`, never another `\Throwable`.
The corpus is `encode()` of every method with random fields, mutated: bit flips, truncation, changed lengths.
Built like `BufferFuzzTest`: seeds in a `DataProvider`, a failure is reproduced by its seed.

### Round trip of every method

`decode(encode($method)) == $method` for every method of `Internal\Protocol` on random values of the whole range,
the bounds included: `0`, `2³¹`, `PHP_INT_MAX`, an empty and a 255-byte short string, nested tables and arrays.

### `readTable()` and `readArray()` do not check their end

They read while the buffer is longer than the declared end, but do not check that the last value ends exactly
at it. A declared length shorter than the content makes the reading run into the bytes of the parent table
silently. A table with a wrong length must give `ProtocolError`.

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

Infection (the `testo-mutation-testing` skill) on `io\Buffer`, the protocol of amqp and `time`: it shows
where the tests run the code but check nothing.

## Order

1. Fuzzing the frame decoder, the round trip, the end of `readTable()`.
2. Publisher confirms against a model.
3. The rest as time permits.
