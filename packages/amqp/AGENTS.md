# thesis/amqp

AMQP 0.9.1 client for RabbitMQ. Uses `net` (the socket), `io` (`Buffer` codes every frame), `async` (every wait,
the group of `Client::run()`, ADR 0001) and `time`. The API and the guarantees are in `README.md` — read it first.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- The reader (`Connection::run()`) never waits and never runs user code: a delivery goes into an unbounded
  `Async\Channel` (`trySend()`), a reply completes a `Deferred`. A reader that waited on a slow consumer
  would stop heartbeats, confirms and every other channel of the connection.
- `src/Internal/Protocol/*` (except `Method`, `ProtocolError`, `functions.php`) and `src/ReplyCode.php` are generated:
  change `codegen/generate.php` and run `php packages/amqp/codegen/generate.php`. `CodegenTest` fails on a stale file.
  The generated code passes the fixer as it is: a change of the generator is checked with `make fixer P=amqp`.
- A synchronous method holds `callMutex` until its reply arrives, even if the caller is cancelled meanwhile:
  the reply still comes, and the next call must not take it for its own. The reader releases the lock.
- Confirm tags are assigned and the frames written under `publishMutex`, in one order. A write that throws
  with the connection intact (nothing went out) gives its tags back; once a byte went out, net closes the socket.
  A batch takes consecutive tags, so the first one identifies all.
- `$settledUpTo` is the highest tag settled with `multiple`: a later ack of a lower tag is dropped,
  otherwise RabbitMQ closes the channel with PRECONDITION_FAILED (unknown delivery tag).
- A mandatory message carries the header `x-thesis-return-id`: basic.return names no delivery tag, the header
  correlates the return with its confirm. It is removed from the returned message.
- `noWait` is always false: without a reply an error of the broker cannot be attributed to its call.
- Channel open and the rollback of `transactional()` run under `shield()`: a cancelled caller must not leave
  a half-open channel or an open transaction.
- Heartbeats cost no clock read per frame: the reader and the writer set flags, a daemon checks them every half
  of the interval. 4 silent halves (2 intervals) close the connection, as RabbitMQ does on its side.
- Field tables: an int is written as `I` if it fits 32 bits, else `l`; an empty array is a table, a list an array.
  A decimal is read as a numeric string. Types are those of RabbitMQ, not of the spec (`s` is a short int).

## Decisions

- The codegen source is `codegen/amqp0-9-1.extended.xml` of RabbitMQ: the spec with the extensions of RabbitMQ
  (confirms, `basic.nack`, `connection.blocked`, `exchange.bind`). The output is committed, not generated on install.
- `publish()` with confirms blocks until the confirm: the result of a publish is its confirm. Rejected: a future
  per publish (users forget to await it) and a callback (it runs user code in the reader).
- No topology recovery after a reconnect: the application declares and consumes again. Rejected: replaying
  declarations, it hides errors and repeats them in an order the application does not control.
- Removed from the API of the older thesis/amqp: `noWait`, `immediate` (RabbitMQ rejects it), bcmath for decimals,
  `Config::fromArray()`, its own time span type (`Time\Duration` instead).
- `MethodTest` skips the lowercase files of `src/Internal/Protocol`: `class_exists('decodeMethod')` includes the file
  of functions a second time and redeclares them.
- The fake broker of the tests (`FakeServer`, `FakePeer`) speaks the protocol over a real local TCP socket,
  so `Client` is tested end to end. Heartbeat tests run on `#[VirtualTime]` of thesis/testo.
- Not done yet: integration tests against a real RabbitMQ, an RPC helper with direct reply-to.
