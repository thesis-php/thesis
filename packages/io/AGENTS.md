# thesis/io

Fast binary I/O: `Buffer` with typed big/little-endian encoding and varints, the `Reader`, `Writer`, `Closer`
interfaces and `Stream` over a blocking PHP stream resource. Layer: `io`. No event loop, no extensions.
The API and the design decisions are described in `README.md` — read it first.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants

- `Buffer` is the hot path of every binary protocol: a change to it is justified by a benchmark, not by taste.
  Its micro-optimizations (properties with defaults instead of `readonly`, bounds checks folded into the byte fetch,
  `chr()` instead of `pack()` for 1-2 bytes, `clone` in `slice()`) are measured: keep them and their comments.
- Every read is O(1): the cursor advances, consumed bytes are discarded only in `fill()`.
- A failed read throws `UnexpectedEof` and leaves the cursor where it was.
- Range checks of written values are `assert()` only, never a runtime `if`: they cost nothing in production.
- 64-bit PHP is required (`php-64bit`): `int` is int64. `readUint64()` throws `\OverflowException` above
  `PHP_INT_MAX`, varints keep the raw 64-bit pattern.
- Marks of `reserve()` are absolute positions, invalidated by `fill()`, `flush()` and `drain()`.
- `Reader::read()` returns 1 to `$max` bytes and throws `UnexpectedEof` at the end, `Writer::write()` writes all bytes,
  `Closer::close()` is idempotent.

## Tests

- `BufferFuzzTest` compares `Buffer` with the deliberately naive `ReferenceBuffer` on random sequences of operations.
  A new `Buffer` method gets a counterpart in `ReferenceBuffer` with a different algorithm and joins the fuzz test.
