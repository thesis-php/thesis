# Roadmap

The packages that replace amphp and rewrite the older thesis packages. Out of scope for now: kafka, grpc, message-bus,
dic, norm, the modules, and what depends on them (`etcd`, `ydb`, `opentelemetry-grpc-transport`, `schema-registry`,
the protobuf family). This file is a plan, not a decision: a decision goes to a topic of `agents/`
or to the `AGENTS.md` of its package, and a step that is done is deleted from here.

## amphp → thesis

### Replaced, with gaps

| amphp | thesis | Missing |
|---|---|---|
| `amp`, `pipeline` | `async` | — (pipeline operators are generators with `each()`/`map()`) |
| `sync` | `async` | Cross-process primitives (`PosixSemaphore`, `SharedMemoryParcel`): they belong to `parallel` |
| `byte-stream` | `io` | zlib streams and an in-memory pipe, with HTTP (phase 5): nobody needs them before |
| `parser` | `io` | — (`Buffer::readUntil()`) |
| `socket` | `net` | UDP, a pool of connections, passing sockets between processes (for `cluster`) |
| `dns` | `net` | Check the hosts file and `resolv.conf` |
| `uri` | `Uri\WhatWg\Url` | — |
| `cache` | `cache` | A Redis cache, with `redis` |
| `phpunit-util` | Testo, `testo` | — |

### To do

What amphp has, then what thesis makes of it.

- HTTP (`http`, `hpack`, `http-client`, `http-server`, `http-tunnel`): `http`, `http-client`, `http-server`;
  HPACK inside HTTP/2, CONNECT inside the client.
- HTTP client extensions (`http-client-cookies`, `http-client-cache`, `http-client-psr7`): cookies and cache
  as interceptors of the client, a PSR-18 bridge.
- HTTP server extensions (`http-server-router`, `http-server-form-parser`, `http-server-static-content`):
  router and form parser, static content later.
- WebSocket (`websocket`, `websocket-client`, `websocket-server`): `websocket`.
- SQL (`postgres`, `mysql`, `sql`, `sql-common`): `postgres`, later `mysql`; a common `sql` only with the second driver.
- Redis (`redis`): `redis`: RESP3, pub/sub, distributed `Mutex`/`RateLimiter` behind the interfaces of `async`.
- Processes (`process`): `process`.
- Parallelism (`parallel`, `parallel-functions`, `serialization`): `parallel`: workers on `proc_open`
  (ext-parallel optional), an IPC channel, a pool.
- Cluster (`cluster`): `cluster`: a multi-process server, passing sockets (ext-sockets).
- Files (`file`): `file`: PHP has no non-blocking file I/O, the driver is an open question.

### Not carried over

- `log`: psr/log and the non-blocking stdout of `io` are enough, a Monolog handler may come as a bridge.
- `rpc`: grpc takes its place. `http-server-session`, `http-client-guzzle-adapter`, `mysql-dbal`: application level
  or not needed.
- Stale or niche: `quic`, `ssh`, `beanstalk`, `stomp`, `dbus`, `react-adapter`, `react-stream-adapter`.
- `injector`, `dependency-graph`: overlap with dic.
- Infrastructure: `ext-uv` (a Revolt driver, used as is), `ext-fiber`, `green-thread`,
  `windows-process-wrapper` (Windows is not supported, `agents/platform.md`), `php-cs-fixer-config`,
  `template`, the websites.

## Older thesis → thesis

- Merged: `byte-*`, `endian`, `varint`, `amp-bridge` into `io`; `time-span`, `clock` into `time`.
- Not needed: `sync-once` (see "Decisions" of `async`), `package-version` (`Composer\InstalledVersions`).
- Rewritten: `exceptionally`, `formatter`.
- Later, with message-bus and norm: `message`, `transaction`, `dispatcher`, `serde`.
- Rewritten on the new stack: see the phases below.

## Phases

1. **Foundation gaps.** `net`: a pool of connections, UDP.
   Integration tests against services in `compose.yaml` (rabbitmq, nats,
   postgres, memcached, ...) and a convention for a package to declare the service it needs.
2. **`amqp`**, the reference client of `agents/clients.md`. Then a topic on what protocol clients share:
   framing, reconnects with a backoff (`Async\Backoff`), configuration from a DSN,
   `ConnectionFailed`/`ConnectionClosed`.
3. **Protocol clients** on `net` and `io`: `nats` (the second client), `rabbitmq-stream`, `memcached`
   (the first user of the pool), `nsq` (TCP first, lookupd after HTTP), `cassandra`, `mongodb`
   (check what the old repository has; probably from scratch).
4. **SQL.** Ideas and a benchmark of the driver: [postgres-ideas.md](postgres-ideas.md). `postgres` on the wire
   protocol (pool, transactions, prepared statements, `LISTEN`/`NOTIFY`, `COPY`), designed with norm in mind.
   Then `pgmq`.
5. **HTTP.** Ideas for the messages: [http-ideas.md](http-ideas.md). `http` (messages, headers from the old
   `thesis/headers`, bodies as `io\Reader`), `http-client` (HTTP/1.1, keep-alive, the pool, CONNECT, cookies),
   `http-server` (graceful shutdown by `trapSignals()`, router, form parser), HTTP/2 with HPACK
   (needed by grpc; ALPN is in `net`), then `websocket`.
   `Content-Encoding` needs zlib streams (decorators of `Reader`/`Writer`, in `io` or `http`). A first version
   found: `deflate_add()` ignores a flush with empty input, so a writer keeps the last bytes to flush them with;
   the output of one `inflate_add()` cannot be limited, so a reader feeds it 8 KiB at a time (deflate expands
   up to ~1032 times); a reader stops at the end of the compressed stream, not reading the inner one further.
   A request body needs an in-memory pipe from the parser to the handler. A first version found: a write waits
   for room *before* it buffers, then buffers the whole write (a cancelled write delivers nothing, writes never
   interleave); `closeWrite($error)` gives the reader `ReadFailed`, so a body cut short does not look complete;
   `close()` by the reader stops the writer.
6. **Processes.** `process`, then `parallel` (ideas: [parallel-ideas.md](parallel-ideas.md)), then `cluster`.
   On top of them: `hot-reload` (`process`, `http-server`, `trapSignals()`) and `cron` (the parser without async
   and a scheduler on `async`).
7. **Storage and the rest.** `redis` with a Redis cache, `file`, `mysql`.
8. **Cleanup.** After 2.0, the replaced repositories (`amp-bridge`, `byte-*`, `endian`, `varint`, `clock`,
   `time-span`, `sync-once`, `package-version`) are marked abandoned on Packagist with their replacement,
   and their README points to the monorepo.

## Every package

1. Read the old repository: what was right, what we reject and why.
2. Make `packages/<name>/` after an existing package, write `AGENTS.md` (invariants, decisions), `make sync`.
3. Failing tests first, integration tests against the service in compose.
4. A package that takes an older name is taken over as "Taking over an older repository"
   of `agents/repositories.md` says.

## Tooling

- Try [phpstan/tally](https://github.com/phpstan/tally) for benchmarks: it does not depend on wall-clock time
  or the speed of the CPU, so it may be stable enough for CI, unlike `#[Bench]` of Testo.
- A constant or a default value derived from simpler numbers is written as the computation, not its result:
  `1024 * 1024`, not `1_048_576`; `24 * 60 * 60` seconds, not `86_400`; `60 * NANOSECONDS_PER_SECOND`,
  not `60_000_000_000` (PHP folds it at compile time). A bare literal stays where the number itself is the meaning:
  a count (`10_000` messages), a limit of a protocol (`65535`), a bound with no simpler form
  (`Duration::MAX_SECONDS`). Candidates: the buffer sizes of `Buffer`, `Stream`, `SharedStream`, `Socket`,
  `Channel`, `Frames::DEFAULT_MAX`, `DnsResolver::MAX_TTL`, `Connector::ATTEMPT_DELAY`,
  `Scope::LONGEST_DURATION`, the chain of `NANOSECONDS_PER_*` in `thesis/time`. Open: the same for literals
  in code (`4_294_967_295` in `amqp` `Config` as `0xFF_FF_FF_FF` or `2 ** 32 - 1`, the int32 bounds of AMQP,
  the microsecond bounds of `time`). Then the rule goes to `agents/php.md`, next to the one on `_` in literals.

## After the roadmap

Candidates beyond the phases and the out-of-scope list above, not ordered within a group. The first two groups
grow from what `async` already has and amphp lacks, so they come first.

- **Deterministic simulation testing** (as FoundationDB, TigerBeetle): the virtual time of `async` plus an in-memory
  `net` with delays, drops, reordering and partitions, and a scheduler driven by a seed. A client (`amqp`,
  `postgres`, `nats`) runs thousands of times, a failure is replayed by its seed.
- **Observability of the task tree**: a dump of the live tasks with where each is suspended (as a goroutine dump),
  a task suspended longer than a threshold, a deadlock on `Lock`/`Channel`. Trace context carried by `ScopedValue`
  along the tree, the OpenTelemetry API without the grpc transport, metrics for Prometheus over `http-server`.
  The tools around `Trace\observe()`: `trace-ideas.md`.
- **Deadlines across services**: the time left of a cancellation passed on in HTTP and grpc headers
  (`grpc-timeout`) and as `statement_timeout` of postgres.
- **Resilience** on `async`: a circuit breaker, a bulkhead, hedged requests, a retry policy on `Async\Backoff`,
  local and distributed (redis, postgres advisory locks) behind one interface, as `Mutex` and `RateLimiter`.
  Leader election on postgres, redis or etcd.
- **Protocols**: on `net`: SMTP, MQTT, StatsD and syslog (the first users of UDP), ClickHouse native, LDAP.
  On `http-client`: S3 with streamed bodies, the Docker and Kubernetes APIs (a watch as an iterator), ACME
  for the certificates of `http-server`. On `http-server`: Server-Sent Events, later HTTP/3.
- **Formats** as decorators of `Reader`/`Writer`: streamed JSON, msgpack, CBOR, CSV; zstd and brotli beside zlib.
- **Application level**: a job queue with retries, a dead letter queue and idempotency on `pgmq`, `amqp`
  and `redis`; the outbox pattern (`postgres` with `amqp`), a bridge to message-bus. An application server
  on `cluster`, `http-server`, `hot-reload` and `trapSignals()`, with PSR-15 and Symfony Runtime bridges.
- **Tooling**: PHPStan rules in `phpstan-extension` for async code (a blocking call in a fiber: `sleep()`,
  network `file_get_contents()`, PDO; a task nobody awaits; the reference cycles of
  [memory-ideas.md](memory-ideas.md)). Rector rules to migrate from amphp. A documentation site and benchmarks
  against amphp, ReactPHP and Swoole.

## Open questions

1. Protobuf: wait for grpc, or rewrite it earlier as a standalone serializer?
2. Postgres: pure PHP on the wire protocol rather than ext-pgsql or pecl-pq? The benchmark says yes
   ([postgres-ideas.md](postgres-ideas.md)): typed rows 1.8 times faster than ext-pgsql, pipelining it lacks.
3. HTTP/2: right after HTTP/1.1, or only with grpc?
4. The pool: a generic resource pool in `async` or a pool of connections in `net`?
5. The driver of `file`: through `parallel` (the default of amphp), ext-uv, or blocking only?
