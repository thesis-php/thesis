# Roadmap

The packages that replace amphp and rewrite the older thesis packages. Out of scope for now: kafka, grpc, message-bus,
dic, norm, the modules, and what depends on them (`etcd`, `ydb`, `opentelemetry-grpc-transport`, `schema-registry`,
the protobuf family). This file is a plan, not a decision: a decision goes to an ADR or to the `AGENTS.md`
of its package, and a step that is done is deleted from here.

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
| `phpunit-util` | Testo, `testo` | — |

### To do

| Area | amphp | thesis |
|---|---|---|
| HTTP | `http`, `hpack`, `http-client`, `http-server`, `http-tunnel` | `http`, `http-client`, `http-server`; HPACK inside HTTP/2, CONNECT inside the client |
| HTTP client extensions | `http-client-cookies`, `http-client-cache`, `http-client-psr7` | Cookies and cache as interceptors of the client, a PSR-18 bridge |
| HTTP server extensions | `http-server-router`, `http-server-form-parser`, `http-server-static-content` | Router and form parser, static content later |
| WebSocket | `websocket`, `websocket-client`, `websocket-server` | `websocket` |
| SQL | `postgres`, `mysql`, `sql`, `sql-common` | `postgres`, later `mysql`; a common `sql` only with the second driver |
| Redis | `redis` | `redis`: RESP3, pub/sub, distributed `Mutex`/`RateLimiter` behind the interfaces of `async` |
| Cache | `cache` | A cache interface and a local cache with TTL; the Redis one in `redis` |
| Processes | `process` | `process` |
| Parallelism | `parallel`, `parallel-functions`, `serialization` | `parallel`: workers on `proc_open` (ext-parallel optional), an IPC channel, a pool |
| Cluster | `cluster` | `cluster`: a multi-process server, passing sockets (ext-sockets) |
| Files | `file` | `file`: PHP has no non-blocking file I/O, the driver is an open question |

### Not carried over

- `log`: psr/log and the non-blocking stdout of `io` are enough, a Monolog handler may come as a bridge.
- `rpc`: grpc takes its place. `http-server-session`, `http-client-guzzle-adapter`, `mysql-dbal`: application level
  or not needed.
- Stale or niche: `quic`, `ssh`, `beanstalk`, `stomp`, `dbus`, `react-adapter`, `react-stream-adapter`.
- `injector`, `dependency-graph`: overlap with dic.
- Infrastructure: `ext-uv` (a Revolt driver, used as is), `ext-fiber`, `green-thread`,
  `windows-process-wrapper` (Windows is not supported, ADR 0005), `php-cs-fixer-config`, `template`, the websites.

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
2. **`amqp`**, the reference client of ADR 0001. Then an ADR on what protocol clients share: framing, reconnects
   with a backoff (`Async\Backoff`), configuration from a DSN, `ConnectionFailed`/`ConnectionClosed`.
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
6. **Processes.** `process`, then `parallel`, then `cluster`. On top of them: `hot-reload` (`process`,
   `http-server`, `trapSignals()`) and `cron` (the parser without async and a scheduler on `async`).
7. **Storage and the rest.** `redis` with `cache`, `file`, `mysql`.
8. **Cleanup.** After 2.0, the replaced repositories (`amp-bridge`, `byte-*`, `endian`, `varint`, `clock`,
   `time-span`, `sync-once`, `package-version`) are marked abandoned on Packagist with their replacement,
   and their README points to the monorepo.

## Every package

1. Read the old repository: what was right, what we reject and why.
2. Make `packages/<name>/` after an existing package, write `AGENTS.md` (invariants, decisions), `make sync`.
3. Failing tests first, integration tests against the service in compose.
4. A package that takes an older name gets its old tags by hand (ADR 0002, 7).

## Tooling

- Try [phpstan/tally](https://github.com/phpstan/tally) for benchmarks: it does not depend on wall-clock time
  or the speed of the CPU, so it may be stable enough for CI, unlike `#[Bench]` of Testo.
- Links between packages in a README (`[thesis/async](../async/README.md)` in `amqp`, `async`, `io`, `net`)
  work only in the monorepo: the split repository and Packagist have no `../async`. Replace them all at once
  with links to the repositories of the packages (`https://github.com/thesis-php/async`), and make `bin/monorepo`
  check that a README links no path outside its package.

## Open questions

1. Protobuf: wait for grpc, or rewrite it earlier as a standalone serializer?
2. Postgres: pure PHP on the wire protocol rather than ext-pgsql or pecl-pq? The benchmark says yes
   ([postgres-ideas.md](postgres-ideas.md)): typed rows 1.8 times faster than ext-pgsql, pipelining it lacks.
3. HTTP/2: right after HTTP/1.1, or only with grpc?
4. The pool: a generic resource pool in `async` or a pool of connections in `net`?
5. The driver of `file`: through `parallel` (the default of amphp), ext-uv, or blocking only?
6. The cache: a package of its own or a part of `async`?
