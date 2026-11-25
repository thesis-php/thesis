# Roadmap

This roadmap lists packages that replace amphp or rewrite older Thesis packages. The current plan excludes
kafka, grpc, message-bus, dic, norm, the modules and their dependents. These include `etcd`, `ydb`,
`opentelemetry-grpc-transport`, `schema-registry` and the protobuf family. Record decisions in the relevant
agent topic or package `AGENTS.md`. Remove steps from the roadmap as they are completed.

## amphp → thesis

### Replaced, with gaps

| amphp | thesis | Missing |
|---|---|---|
| `amp`, `pipeline` | `async` | — (pipeline operators are generators with `each()`/`map()`) |
| `sync` | `async` | Cross-process primitives (`PosixSemaphore`, `SharedMemoryParcel`): they belong to `parallel` |
| `byte-stream` | `io` | zlib streams and an in-memory pipe, with HTTP (phase 1): nobody needs them before |
| `parser` | `io` | — (`Buffer::readUntil()`) |
| `socket` | `net` | UDP, passing sockets between processes (for `cluster`); pools live in their packages |
| `dns` | `net` | Check the hosts file and `resolv.conf` |
| `uri` | PHP URI classes | HTTP uses `Uri\Rfc3986\Uri` to preserve raw paths and queries |
| `cache` | `cache` | A Redis cache, with `redis` |
| `log` | `log` | — (PSR-3 with NDJSON and console output through `io`) |
| `phpunit-util` | Testo, `testo` | — |

### To do

What amphp has, then what thesis makes of it.

- The `http` package contains the client, server, messages and codec;
  HPACK inside HTTP/2, CONNECT inside the client.
- HTTP client extensions (`http-client-cookies`, `http-client-cache`, `http-client-psr7`): cookies and cache
  as interceptors of the client, a PSR-18 bridge.
- HTTP server extensions (`http-server-form-parser`, `http-server-static-content`):
  form parser, static content later.
- WebSocket (`websocket`, `websocket-client`, `websocket-server`): `websocket`.
- SQL (`postgres`, `mysql`, `sql`, `sql-common`): `postgres`, later `mysql`; a common `sql` only with the second driver.
- Redis (`redis`): `redis`: RESP3, pub/sub, distributed `Mutex`/`RateLimiter` behind the interfaces of `async`.
- Processes (`process`): `process`.
- Parallelism (`parallel`, `parallel-functions`, `serialization`): `parallel`: workers on `proc_open`
  (ext-parallel optional), an IPC channel, a pool.
- Cluster (`cluster`): `cluster`: a multi-process server, passing sockets (ext-sockets).
- Files (`file`): `file`: PHP has no non-blocking file I/O, the driver is an open question.

### Not carried over

- `rpc`: grpc takes its place. `http-server-session`, `http-client-guzzle-adapter`, `mysql-dbal`: application level
  or not needed.
- Stale or niche: `quic`, `ssh`, `beanstalk`, `stomp`, `dbus`, `react-adapter`, `react-stream-adapter`.
- `injector`, `dependency-graph`: overlap with dic.
- Infrastructure: `ext-uv`, `ext-fiber`, `green-thread`,
  `windows-process-wrapper` (Windows is not supported, `agents/platform.md`), `php-cs-fixer-config`,
  `template`, the websites.

## Older thesis → thesis

- Merged: `byte-*`, `endian`, `varint`, `amp-bridge` into `io`; `time-span`, `clock` into `time`.
- `Thesis\Async\Once` in `thesis/async` replaces `sync-once`.
- Not needed: `package-version` (`Composer\InstalledVersions`).
- Rewritten: `exceptionally`, `formatter`, `dispatcher`.
- Later, with message-bus and norm: `message`, `transaction`, `serde`.
- Rewritten on the new stack: see the phases below.

## Phases

The order follows an [uptime monitor](uptime-monitor.md) to be written on thesis without amphp:
an HTTP server with SSE, cookies and static files, an HTTP client for checks, postgres with
`LISTEN`/`NOTIFY`, password hashing in workers.
A pool of connections is written in each package that needs it (`agents/services.md`).

1. **HTTP.** [`http`](../packages/http/README.md) provides the shared messages, headers, body readers and HTTP/1 codec.
   Next comes the HTTP server (graceful shutdown with `trapSignals()`, middleware, streamed responses for SSE and static files),
   then the HTTP client (HTTP/1.1, keep-alive, its own pool, redirects and CONNECT). Cookie support,
   HTTP/2 with HPACK for grpc, and WebSocket are planned. See the [client and server design](http.md).
   `Content-Encoding` needs zlib streams (decorators of `Reader`/`Writer`, in `io` or `http`). A first version
   found: `deflate_add()` ignores a flush with empty input, so a writer keeps the last bytes to flush them with;
   the output of one `inflate_add()` cannot be limited, so a reader feeds it 8 KiB at a time (deflate expands
   up to ~1032 times); a reader stops at the end of the compressed stream, not reading the inner one further.
   The proposed HTTP/1 handler reads directly from the body decoder. An in-memory pipe could connect
   an SSE producer to a response reader. A first version found: a write waits
   for room *before* it buffers, then buffers the whole write (a cancelled write delivers nothing, writes never
   interleave); `closeWrite($error)` gives the reader `ReadFailed`, so a body cut short does not look complete;
   `close()` by the reader stops the writer.
2. **Postgres.** Ideas and a benchmark of the driver: [postgres.md](postgres.md). `postgres` on the wire
   protocol (its own pool, transactions, prepared statements, `LISTEN`/`NOTIFY`, errors with SQLSTATE, `COPY`),
   designed with norm in mind. Then `pgmq`.
3. **Processes.** `process`, then `parallel` (ideas: [parallel.md](parallel.md)).
4. **`hot-reload`** on `process`, `trapSignals()` and the HTTP server, instead of the one on amphp.
5. **Later**, in no fixed order:
   - `net`: UDP, passing sockets between processes; check the hosts file and `resolv.conf` of the resolver;
   - a common pool, once the HTTP client and `postgres` have their own;
   - what protocol clients share, a topic drawn from `amqp` (framing, reconnects with `Async\Backoff`,
     configuration from a DSN, `ConnectionFailed`/`ConnectionClosed`), and a convention for a package
     to declare its service in `compose.yaml`;
   - protocol clients on `net` and `io`: `nats`, `rabbitmq-stream`, `memcached`, `nsq` (TCP first, lookupd after
     HTTP), `cassandra`, `mongodb` (check what the old repository has; probably from scratch);
   - `cluster` and `cron` (the parser without async and a scheduler on `async`);
   - `redis` with a Redis cache, `file`, `mysql`.
6. **Cleanup.** After 2.0, the replaced repositories (`amp-bridge`, `byte-*`, `endian`, `varint`, `clock`,
   `time-span`, `sync-once`, `package-version`) are marked abandoned on Packagist with their replacement,
   and their README points to the monorepo.

## Every package

1. Read the old repository: what was right, what we reject and why.
2. Make `packages/<name>/` after an existing package, write `AGENTS.md` (invariants, decisions), `make sync`.
3. Failing tests first, integration tests against the service in compose.
4. A package that takes an older name is taken over as "Taking over an older repository"
   of `agents/repositories.md` says.

## After the roadmap

Candidates beyond the phases and the out-of-scope list above, not ordered within a group. The first two groups
grow from what `async` already has and amphp lacks, so they come first.

- **Deterministic simulation testing** (as FoundationDB, TigerBeetle): the virtual time of `async` plus an in-memory
  `net` with delays, drops, reordering and partitions, and a scheduler driven by a seed. A client (`amqp`,
  `postgres`, `nats`) runs thousands of times, a failure is replayed by its seed.
- **Observability of the task tree**: a dump of the live tasks with where each is suspended (as a goroutine dump),
  a task suspended longer than a threshold, a deadlock on `Lock`/`Channel`. Trace context carried by `ScopedValue`
  along the tree, the OpenTelemetry API without the grpc transport, metrics for Prometheus over the HTTP server.
  The tools around `Trace\observe()`: [trace.md](trace.md).
- **Other models of concurrency** on top of the task tree: lazy tasks, `select()` over operations, effects,
  transparent futures, actors: [async.md](async.md). The next stages of the event loop: [loop.md](loop.md).
- **Deadlines across services**: the time left of a cancellation passed on in HTTP and grpc headers
  (`grpc-timeout`) and as `statement_timeout` of postgres.
- **Resilience** on `async`: a circuit breaker, a bulkhead, hedged requests, a retry policy on `Async\Backoff`,
  local and distributed (redis, postgres advisory locks) behind one interface, as `Mutex` and `RateLimiter`.
  Leader election on postgres, redis or etcd.
- **Protocols**: on `net`: SMTP, MQTT, StatsD and syslog (the first users of UDP), ClickHouse native, LDAP.
  On the HTTP client: S3 with streamed bodies, the Docker and Kubernetes APIs (a watch as an iterator), ACME
  for the certificates of the HTTP server. On the HTTP server: Server-Sent Events, later HTTP/3.
- **Formats** as decorators of `Reader`/`Writer`: streamed JSON, msgpack, CBOR, CSV; zstd and brotli beside zlib.
- **Application level**: a job queue with retries, a dead letter queue and idempotency on `pgmq`, `amqp`
  and `redis`; the outbox pattern (`postgres` with `amqp`), a bridge to message-bus. An application server
  on `cluster`, the HTTP server, `hot-reload` and `trapSignals()`, with PSR-15 and Symfony Runtime bridges.
- **Tooling**: PHPStan rules in `phpstan-extension` for async code (a blocking call in a fiber: `sleep()`,
  network `file_get_contents()`, PDO; a task nobody awaits; the reference cycles of
  [memory.md](memory.md)). Rector rules to migrate from amphp. A documentation site and benchmarks
  against amphp, ReactPHP and Swoole.

## Open questions

1. Protobuf: wait for grpc, or rewrite it earlier as a standalone serializer?
2. Postgres: pure PHP on the wire protocol rather than ext-pgsql or pecl-pq? The benchmark says yes
   ([postgres.md](postgres.md)): typed rows 1.8 times faster than ext-pgsql, pipelining it lacks.
3. HTTP/2: right after HTTP/1.1, or only with grpc?
4. The driver of `file`: through `parallel` (the default of amphp), ext-uv, or blocking only?
5. Waits outside a group: answered by the loop of our own (`packages/async/AGENTS.md`), the body of `group()` in `{main}`
   runs in a fiber of its own and `suspend()` in the root scope throws `LogicException`.
