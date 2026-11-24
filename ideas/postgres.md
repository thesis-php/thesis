# Postgres: ideas

A draft for phase 2 of the roadmap, not a decision. What survives the discussion goes to the "Decisions"
of `packages/postgres/AGENTS.md` (or to a topic of `agents/`, if `sql` shares it with `mysql`),
and this file is deleted.

## The driver: pure PHP on the wire protocol

Protocol v3 implemented in PHP on `net` and `io`, like `amqp`. pgx (Go), tokio-postgres (Rust), node-postgres
and asyncpg (Python) do the same; asyncpg beats the libpq drivers with the binary format and pipelining.

What we get that libpq does not give:

- DNS, TLS and cancellation of `net`: `SSLRequest` then `enableTls()` (PG 17+ also direct TLS with ALPN
  `postgresql`), the async resolver and Happy Eyeballs, a `CancelRequest` on a separate connection
  when the task waiting for a query is cancelled.
- Pipelining for free: the extended protocol sends Parse/Bind/Execute of many queries before one Sync.
- The binary format per column, rows streamed instead of a buffered result, `COPY` as `io\Reader`/`Writer`,
  `NOTIFY` as asynchronous messages in the reader loop of `agents/clients.md`.
- Tests on a fake server and on the virtual time of `testo-plugin`. No extension to install.

Rejected:

- **ext-pgsql** (amphp/postgres uses it): libpq resolves a host name by a blocking `getaddrinfo()` even
  in an async connect, so the event loop stops; TLS is libpq's own, `ClientTls` does not apply;
  `pg_cancel_query()` is synchronous; `COPY` and `NOTIFY` are polled; results are text only;
  **no pipeline mode** (PHP 8.5 has no `pg_enter_pipeline_mode()`); no fake server in tests.
- **pecl-pq**: the same libpq problems, plus a PECL extension with `raphf` that is rarely installed.
- **Two drivers** (pure PHP plus ext-pgsql as a fallback, like amphp): every feature twice, and the libpq one
  still lacks binary, cancellation and async DNS.

Risks that stay: authentication (SCRAM-SHA-256 is easy with `hash_pbkdf2()` and `hash_hmac()`, md5 and cleartext
too; SCRAM-SHA-256-PLUS needs the hash of the server certificate from `TlsInfo`; GSSAPI, Kerberos and SSPI
are rejected), compatibility with libpq (`PG*` variables, `.pgpass`, `pg_service.conf`: later, as a function
that builds a config, not in the core).

## The benchmark

Done to check the main risk of pure PHP: the speed of decoding `DataRow`. A prototype client in one file
(not kept: this section has what it found), PostgreSQL 18 and PHP 8.5.12 without xdebug, opcache on,
in Docker Desktop on arm64 (native, no emulation), `trust` auth, a table of 100k rows
`(id int4, big int8, f float8, flag bool, name text, note text)` with every third `note` NULL.
Every variant returned exactly what ext-pgsql returns after a conversion by the oids of the columns
(an md5 of `serialize()` of all the results, types included).

The variants:

- `pgsql`: `pg_execute()` and `pg_fetch_all()`, values are strings.
- `pgsql-typed`: the same plus a conversion by `pg_field_type_oid()` in a PHP loop, what a driver over ext-pgsql does.
- PHP: text or binary format, a generic decoder (a loop with `match`) or a generated one (see below),
  blocking or on an event loop; `skip` frames the messages without decoding them, the floor of a PHP client.

CPU is the time of the PHP process (`getrusage()`, user + sys), the median; JIT off (tracing JIT changes
the decoding by 5–10% only):

| Scenario | `pgsql` | `pgsql-typed` | PHP text, generated | PHP binary, generated | PHP `skip` |
|---|---|---|---|---|---|
| `rt`: `SELECT $1::int4`, CPU per query | 26 µs | 26 µs | 27 µs | 26 µs | 24 µs |
| `rows20`: 20 rows, CPU per query | 44 µs | 54 µs | 43 µs | **41 µs** | 30 µs |
| `rows100k`: CPU per row | 0.66 µs | 1.13 µs | 0.80 µs | **0.62 µs** | 0.18 µs |
| `rows100k`: wall time per row | 0.79 µs | 1.26 µs | 0.80 µs | **0.63 µs** | 0.30 µs |
| `pipeline`: 1000 point selects, wall time per query | 88 µs | 104 µs | 11.9 µs | **11.6 µs** | 11.4 µs |

- A pure PHP client decodes typed rows 1.8 times faster than ext-pgsql with a conversion, and a little faster
  than ext-pgsql giving bare strings.
- One query is the same everywhere: ~25 µs of CPU are syscalls and the network of the Docker VM,
  the round trip is ~75 µs. The client is invisible there.
- Pipelining is the largest win: 9 times on a local round trip, more on a real network.
  ext-pgsql runs the queries one by one.
- The third-party event loop of the measurement, before `Internal\Loop`, added ~15 µs of CPU per query
  for ext-pgsql and PHP alike: the cost of the loop, not of the protocol. To measure again on ours.
- The generated decoder takes 17% less than the generic one (0.62 against 0.75 µs per row),
  binary 22% less than text (0.62 against 0.80).

## How to decode fast in PHP

Measured offline on a buffer of 100k messages of the same shape (no network), ns per operation, JIT off / on:

| Operation | JIT off | JIT on |
|---|---|---|
| `unpack('N', $b, $p)[1]` | 59 | 46 |
| `unpack('J', ...)[1]`, `unpack('E', ...)[1]` | 79, 52 | 53, 50 |
| `ord()` ×4 for an int32 | 125 | 72 |
| `unpack()` of 8 named values (`Nl0/Nv0/...`) | 241 | 224 |
| `$b[$p] === "\xFF"` | 22 | 5 |
| `substr($b, $p, 37)` | 28 | 22 |

- **`unpack()` is the cost**: ~45 ns a call (it parses the format and allocates the result array). Count calls,
  not bytes.
- **A NULL is told by the first byte of the length**: `0xFF` there only in -1, a real length is below 1 GiB.
  **The length of a fixed-width column is not read**: the server always sends 2/4/8/1 bytes for
  `int2`/`int4`/`int8`/`bool`, 4 for `float4`, 8 for `float8`. Together −28% per row.
- **A decoder generated per `RowDescription`** (`eval()` once per row shape, cached): columns unrolled,
  the keys of the row and the conversions are literals, the row is an array literal. −17% against a loop.
- **The binary format where it is cheaper**: the format codes of Bind are per column, so binary for the types
  with a decoder, text for the rest. `text`/`varchar` are the same bytes in both formats.
- **One read buffer parsed in place** by an offset, no copy per message. The decoder gets the buffer
  and decodes every complete `DataRow` from the offset, so a call costs per read, not per row.
- What did not help: one named `unpack()` for a run of fixed-width columns (an 8-key hash is dearer than
  separate calls), `ord()` instead of `unpack()`, `gc_disable()`. A list instead of an assoc row saves ~3%.
- What is left is the floor: framing (one `unpack()` for the length of a message) ~50 ns per row, allocating
  the row and its strings ~190 ns per row of 6 columns. Not reading the length of a message saves 10% more,
  but then a message that has not fully arrived cannot be told: rejected.

The generated code for `(id int4, flag bool, name text)`:

```php
static function (string $b, int $pos, int $len, array &$rows): int {
    while ($len - $pos >= 5 && $b[$pos] === 'D') {
        $end = $pos + 1 + unpack('N', $b, $pos + 1)[1];
        if ($end > $len) { return $pos; } // not fully read yet
        $p = $pos + 7; // type, length, the number of columns
        if ($b[$p] === "\xFF") { $c0 = null; $p += 4; }
        else { $c0 = (unpack('N', $b, $p + 4)[1] ^ 0x80000000) - 0x80000000; $p += 8; } // signed int32
        if ($b[$p] === "\xFF") { $c1 = null; $p += 4; } else { $c1 = $b[$p + 4] === "\1"; $p += 5; }
        if ($b[$p] === "\xFF") { $c2 = null; $p += 4; }
        else { $l = unpack('N', $b, $p)[1]; $c2 = substr($b, $p + 4, $l); $p += 4 + $l; }
        $rows[] = ['id' => $c0, 'flag' => $c1, 'name' => $c2];
        $pos = $end;
    }
    return $pos;
}
```

Not measured: pecl-pq (it builds on PHP 8.5: pq 2.2.3 with libpq 18.7), authentication and connect, a real network,
types without a cheap decoder (`numeric`, `timestamptz`, `jsonb`, arrays), `Internal\Loop` of thesis/async.

## The API

amphp gets its many types from choices we do not make: two drivers (`PostgresHandle`, `PgSqlConnection`,
`PqConnection`), a common `amphp/sql` with templates (`SqlLink<TResult, TStatement, TTransaction>`) for mysql
and postgres to narrow, `sql-common` with pooled results, statements and nestable transactions, statements
as objects bound to a connection, transactions committed by hand, an interface for every entity.
Here: one driver, no `sql` until the second driver (the roadmap), no `@template` on a type,
one interface, the rest `final` classes. About 15 types:

- `Executor`, the only interface: what user code takes, so a repository works in a transaction and outside it.
- `Client` (the pool, `start($group)`/`stop()` of `agents/clients.md`), `Config`, `Connection`, `Transaction`,
  `Result`, `Listener`, `Notification`. `Connection` and `Transaction` have `@internal` constructors.
- `PostgresException`, `ConnectionFailed`, `ConnectionClosed`, `QueryFailed`, `SqlState`, `IsolationLevel`.

```php
interface Executor
{
    /** @param list<mixed> $params */
    public function query(string $sql, array $params = []): Result;

    /**
     * @template T
     * @param callable(Transaction): T $body
     * @return T
     */
    public function transaction(callable $body, ?IsolationLevel $isolation = null, bool $readOnly = false): mixed;
}

$client = new Client(Config::fromUri('postgres://app@db/app'));
$client->start($group);

$user = $client->query('SELECT * FROM users WHERE id = $1', [$id])->first();

$client->transaction(static function (Transaction $tx) use ($from, $to, $amount) {
    $tx->query('UPDATE accounts SET balance = balance - $1 WHERE id = $2', [$amount, $from]);
    $tx->query('UPDATE accounts SET balance = balance + $1 WHERE id = $2', [$amount, $to]);
    $tx->transaction(static fn(Transaction $savepoint) => audit($savepoint)); // SAVEPOINT
});

$client->connection(static function (Connection $connection) { /* SET, advisory locks, temp tables */ });
```

- `transaction()` is on `Executor`, so code composes: on `Client` it begins a transaction, on `Transaction`
  a savepoint. It commits if `$body` returns and rolls back under `shield()` if it throws or is cancelled;
  a connection whose rollback failed is closed, not returned to the pool. `Transaction` as a type says
  "call me inside a transaction" in a signature.
- `Result` is a `final` class, buffered: `$rows` (`list<array<string, mixed>>`), `$affected` from CommandComplete,
  `first()`, `value()`. A streamed result on a pooled connection is the trap of amphp: the connection is busy
  until the result is read to the end. Decoding is cheap (the benchmark), so buffering costs little.
- No `prepare()`: statements are cached per connection by the text of the SQL (an LRU that sends `Close`
  on eviction), as in pgx.
- Pipelining comes from concurrency, no API of its own: every query is Parse/Bind/Execute/Sync, sent without
  waiting for the previous one, so fibers sharing a `Connection` or a `Transaction` go out together.
  The Sync per query isolates the errors outside a transaction.
- Duplicate column names (`SELECT a.id, b.id`) throw once, on the RowDescription, asking for an alias.
- Parameters are `$1` only: named parameters are for norm or a query builder.
- `$client->listen('chan')` is a `Listener`, an iterator of `Notification`; all listeners share one dedicated
  connection. On a lost connection it throws `ConnectionClosed` instead of listening again silently:
  the notifications of the gap are lost (as in amqp).
- `COPY` is `copyIn($sql, Reader)` and `copyOut($sql, Writer)` on `io`, named by the protocol.
- One `QueryFailed` with the fields of ErrorResponse (`$detail`, `$hint`, `$constraint`, ...) and `SqlState`.
  The driver uses a shared exception class for errors such as `UniqueViolation`.

Rejected: an ambient transaction in a `ScopedValue` (as `@Transactional`): a query of a library deep in the stack
joins it silently, and the child tasks inherit it through the group.

## The types of a result

At runtime the types are right already: the decoder works by the oid of a column (`int4` is `int`,
not a string as in amphp). Statically `query()` returns `array<string, mixed>`. The fix is an extension
of `thesis/phpstan-extension`, as sqlx (`query!`) and staabm/phpstan-dba do: for a literal or constant SQL
it sends Parse + Describe to a database at analysis time.

- RowDescription gives the names, oids and order of the columns: `list<array{id: int, name: string, ...}>`
  through the table of codecs. ParameterDescription gives the types of `$1..$n`: the count and the types
  of the parameters are checked. An error of the SQL itself is an error of the analysis.
- Nullability is not in Describe: it comes from `pg_attribute.attnotnull` by the table oid and attribute number
  of RowDescription. The trap is an outer join: a `NOT NULL` column on the nullable side of a `LEFT JOIN` is null.
  sqlx parses `EXPLAIN (VERBOSE, FORMAT JSON)`; we start conservative: `NOT NULL` is trusted only in a query
  without an outer join, expressions are nullable. A type wider than the truth is fine, a narrower one is not.
- The answers are cached in a committed file keyed by the SQL (the offline mode of sqlx), so CI needs no database.
  No database and no entry: the type of the PHPDoc.
- The extension requires no package it describes: it takes the table of oid to PHP type from `thesis/postgres`
  if installed. So **a codec declares the PHPStan type of its value**, otherwise user types (enums, citext,
  hstore) stay `mixed`. This goes into the interface of a codec from the start.
- SQL built at runtime keeps `mixed`.

Mapping to objects is norm's: `$result->map(static fn(array $row) => new User(...$row))` gives `list<User>`
through the template of the method. Generated typed methods from `.sql` files (sqlc) are a tool of their own,
later, with norm.

## What the other packages give and what is missing

There: `connect()` with async DNS and Happy Eyeballs, `connectUnix()` for `.s.PGSQL.5432`,
`Socket::enableTls()` after SSLRequest (its PHPDoc names Postgres), ALPN `postgresql` for the direct TLS of PG 17+.
CVE-2021-23222 (bytes injected after `S`) is closed by construction: `read(1)` takes one byte, the rest goes
into the TLS handshake, which fails. `TlsInfo::$peerCertificate` for SCRAM-SHA-256-PLUS (`tls-server-end-point`:
`openssl_x509_fingerprint()` with the algorithm of the signature from `openssl_x509_parse()`).
`onCancel()` for CancelRequest, `shield()`, `timeout()`, `Backoff`, `Async\Channel` for `Listener`,
`Buffer::reserve()`/`patchUint32()` for the length of a message, `listen()` for a fake server.

Missing:

1. A pool: inside `Postgres\Client` on `LocalSemaphore` and a list of idle connections, a common pool only
   once `http-client` has its own too (`agents/clients.md`).
2. `Buffer` keeps `$bytes` and `$offset` private, the generated decoder needs the raw string and an offset
   (`peek()` per column is the ~45 ns per call we measured). Proposed: the reader of postgres keeps its own
   read string, `Buffer` is for writing. `io` is not changed for one package.
3. No postgres in `compose.yaml` and no convention for a package to declare its service:
   a service with a `pg_isready` healthcheck and `POSTGRES_URI`; tests on the minimum and the newest
   supported PostgreSQL.
4. No date types: `time` has `Duration` and clocks only, and `interval` is not a `Duration` (months and days
   depend on the calendar). `timestamptz` as `\DateTimeImmutable` or our own type, `interval` as `\DateInterval`
   or our own, `numeric` as a string or `BcMath\Number` (bcmath is an optional extension).
5. A URI with several hosts (`postgresql://h1:5432,h2:5433/db`) is not parsed by `Uri\WhatWg\Url`;
   `Amqp\Config::fromUri()` splits by commas by hand. A reason for the topic of what clients share
   ("Later" of the roadmap), not written yet.

Smaller: SASLprep of a password needs `Normalizer` of intl, libpq takes an invalid UTF-8 password as is,
so we can too without intl.

## Code generation and the size

The messages are not generated: about 35, irregular (DataRow, RowDescription, ErrorResponse have their own shapes),
and the hot ones are written by hand for speed. AMQP is generated because its spec is machine-readable and its
50 methods are alike. Generated, committed, with a `CodegenTest` on a stale file as in amqp:

- `SqlState` from `src/backend/utils/errcodes.txt`, about 260 codes, as `ReplyCode` of amqp.
- The table of built-in types from `src/include/catalog/pg_type.dat`: oid, name, array oid, element oid,
  `typlen` (which columns are fixed-width and need no length read, −28% in the benchmark), category.

At runtime: the decoder per RowDescription through `eval()` (−17%), with tests of its own.

amqp is 6.5k lines of `src/`: 3.1k by hand, 3.4k generated (a generator of 640), 2k of tests.
postgres is estimated at 5–6k by hand and ~400 generated, tests more than in amqp:

| Part | Lines |
|---|---|
| Codecs, text and binary, encode and decode | 1200–1500 |
| Extended query, the pipelining queue, Sync, recovery after an error | 600–800 |
| The generator of the row decoder | 300–400 |
| The pool | 300–400 |
| Authentication: SCRAM(-PLUS), md5, cleartext, SASLprep | 300–400 |
| Startup and TLS: sslmode, direct TLS, NegotiateProtocolVersion | 300 |
| Config and URI: several hosts, sslmode, `target_session_attrs` | 300 |
| Transactions and savepoints, the statement cache, LISTEN, COPY, cancellation | 700–900 |
| Errors (without the generated `SqlState`) | 200 |

The protocol is simpler than AMQP, the size is in the types, the pool and pipelining.
What makes the codecs cheap to test is oracles, not generation: the same value read in text and in binary
must be equal, ext-pgsql and pdo_pgsql (in the image) as a reference as the benchmark did, `SELECT $1::type`
round trips over a table of edge values (min/max, NaN, ±infinity of dates, empty and multidimensional arrays,
NULL inside an array). Most tests of the codecs are a table of values.

## How to do the work

Not one session: with 5–6k lines and more tests the context is compressed several times, and the reasons
and traps go first. Only `AGENTS.md` and topics survive a session, so the end of a session is where its findings
are written down. A chain of sessions, one stage each (1–2k lines with tests), each ends with a commit and
`packages/postgres/AGENTS.md`, each starts by reading it and this file. The failing test first.

0. Design: the decisions of this file go to `packages/postgres/AGENTS.md`, with the internal contracts:
   the interface of a codec (oid, encode/decode, text/binary, the PHPStan type), how the reader hands
   the answers to the queue of queries, the states of a connection. Without them parallel sessions diverge.
1. The skeleton, sequential, it sets the conventions: the package and its manifest, the postgres service,
   the fake server, `Config::fromUri()`, startup, trust/md5/SCRAM, ErrorResponse, `SqlState` generated,
   simple query, `Client::start()`/`stop()`.
2. The hot core, a session of its own, not split and not finished after a compression: extended query,
   the pipelining queue, Sync, recovery, the statement cache, the table of types, the decoder generator.
   Ends with the benchmark again (0.62 µs per row).
3. The leaves, each needs only the core, can run in parallel: codecs in groups (numbers/bool/text/bytea,
   dates/interval, json/uuid/inet, arrays/enums), the pool and transactions, `LISTEN`/`NOTIFY`, `COPY`,
   cancellation, the TLS modes and SCRAM-PLUS, the extension of `phpstan-extension`.
4. A review by a fresh session (`/code-review`) after every stage. The README last, once the API settles.

Parallel sessions clash with two rules: work on `main` in one working copy (`make test P=postgres` sees
the half-written files of the other session), and one `packages/postgres/AGENTS.md` written by several sessions.
Options: worktrees with temporary branches merged by the user (against "no branches"), or the leaves one by one;
a section of `AGENTS.md` per session.

## More for the design (stage 0)

Cheap now, breaking later:

1. The session is checked at startup by its ParameterStatus: a `client_encoding` other than `UTF8`
   or `standard_conforming_strings` other than `on` rejects the connection instead of corrupting strings.
   `server_version` and `in_hot_standby` (PG 14+) are kept: whether it is a primary or a standby,
   without a query.
2. `timestamptz` is decoded from binary, microseconds since 2000-01-01 UTC, so the value is the same
   on every server; the text format depends on the `DateStyle` and `TimeZone` of the session.
   The ±infinity of dates is decided with it.
3. Safe defaults. `sslmode=prefer` of libpq falls back to plaintext silently and protects from no MITM:
   over TCP the default is `verify-full`, as `ClientTls` verifies by default, and `prefer` is only explicit;
   a Unix socket needs no TLS. `#[\SensitiveParameter]` on the password, parameters of a query never
   in the message of an exception, `channel_binding=require` as an option.
4. NoticeResponse (`RAISE NOTICE`, the warnings of the server) goes to `Result::$notices`, not to a callback:
   the reader never runs user code (`agents/clients.md`).
5. The message of `QueryFailed` shows the line of the SQL with a caret at the `Position` of ErrorResponse,
   as psql does.

## Backlog

Stage 3 and later:

1. Bulk load by binary `COPY`: `$executor->copyRows('users', ['id', 'name'], $rows)` on the codecs, as `CopyFrom`
   of pgx, an order of magnitude faster than batches of `INSERT`. The README gives the tricks for moderate sizes:
   `INSERT … SELECT * FROM unnest($1::int[], $2::text[])`, and `= ANY($1)` instead of expanding `IN (...)`.
2. Retries of a transaction on a serialization failure: `transaction($body, retry: $backoff)` runs `$body` again
   on SqlState `40001` and `40P01` with an `Async\Backoff`; without it `Serializable` is not usable in practice.
   The trap: `$body` must be repeatable (no outside effects), the PHPDoc says so.
3. Advisory locks behind the interfaces of `async`: `PostgresMutex implements KeyedMutex` on `pg_advisory_lock`,
   the distributed `Mutex` of the roadmap's redis without Redis. The lock belongs to a session, so it holds
   a pinned connection.
4. Replicas: several hosts with `target_session_attrs` (`read-write`, `prefer-standby`) by `in_hot_standby`.
   Failover first; routing `transaction(readOnly: true)` to a standby later.
5. Test helpers for users in `testo-plugin`: a test inside a transaction rolled back at its end, parallel tests
   on databases made by `CREATE DATABASE … TEMPLATE` (a copy of the schema in milliseconds, not migrations
   per test).
6. Logical replication (pgoutput) as an iterator of changes, a package of its own: CDC without Debezium,
   the same protocol (`replication=database`, CopyBoth), rare in PHP. Fits message-bus and an outbox later.
7. `pgmq` (in the roadmap): it uses `LISTEN` and the retries of transactions.

Checks of quality:

- pgbouncer in transaction mode: named statements work since 1.21 (`max_prepared_statements`), an option
  of unnamed statements for older ones; `LISTEN` does not work through it, so `Listener` takes a DSN of its own.
  Later CockroachDB, YugabyteDB, Supavisor, RDS Proxy: each has quirks of the binary format and of statements.
- The benchmark in CI with `testo-benchmarks`: the speed of the decoder is the argument for pure PHP,
  a regression is caught by a check, not by chance.
- Observability hooks without a dependency on OpenTelemetry: an event at the start and the end of a query
  in the terms of the `db.*` semantic conventions. One extension point for every client, so it waits for
  the topic of what clients share.

## To discuss next

1. The version of the protocol and the minimum PostgreSQL: 3.0 with 3.2 negotiated by `NegotiateProtocolVersion`
   (PG 18, a longer cancel key), PG 14 or 15 at least?
2. Formats and codecs: binary where a decoder exists, as measured; how a user registers a type;
   the date types and `numeric` (see "What the other packages give", item 4 of "Missing").
3. Cancellation and pipelining: CancelRequest stops what the server runs *now*, so on a `Connection` with queries
   of several fibers it may hit another fiber's query. No problem for `Client::query()` (a connection per query)
   or a `Transaction` (a cancelled query dooms it anyway). Document it, or run the queries of a `Connection`
   one by one?
4. Returning a connection of `connection()` to the pool: `DISCARD ALL` drops the statement cache;
   `RESET ALL` + `UNLISTEN *` + `pg_advisory_unlock_all()`, or close it if the session state was changed?
5. A cursor for large results (a portal with a row limit): an iterator only inside `connection()`/`transaction()`,
   whose end closes the portal, or a callable. Rejected: an iterator on `Client` holding a connection
   until it is read.
6. Parallel sessions: worktrees or the leaves one by one (see "How to do the work").
