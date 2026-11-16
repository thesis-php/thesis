# Postgres: ideas

A draft for phase 4 of the roadmap, not a decision. What survives the discussion goes to the "Decisions"
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
  blocking or on Revolt; `skip` frames the messages without decoding them, the floor of a PHP client.

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
- Revolt adds ~15 µs of CPU per query for ext-pgsql and PHP alike (`pg_send_execute()` + `pg_socket()`
  vs our non-blocking socket): the cost of the loop, not of the protocol.
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
types without a cheap decoder (`numeric`, `timestamptz`, `jsonb`, arrays), the drivers of Revolt other than
the one `DriverFactory` chose.

## To discuss next

1. The version of the protocol and the minimum PostgreSQL: 3.0 with 3.2 negotiated by `NegotiateProtocolVersion`
   (PG 18, a longer cancel key), PG 14 or 15 at least?
2. Formats and codecs: binary where a decoder exists, as measured; how a user registers a type.
3. One connection or a pool, and where the pool lives (open question 4 of the roadmap).
