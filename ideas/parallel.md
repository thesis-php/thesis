# Parallel: ideas

A draft for phase 3 of the roadmap, not a decision. What survives the discussion goes to the "Decisions"
of `packages/parallel/AGENTS.md`, the requirements on `process` to `packages/process/AGENTS.md`,
and this file is deleted.

## What amphp/parallel does

Read at v2.4.0 (about 3900 lines), in three layers:

1. **Context**: a child PHP, a process (`proc_open`), a thread (ext-parallel) or a fork (`pcntl_fork`, "use at your
   own risk"). The parent listens on a socket (`SocketIpcHub`, Unix or TCP) and gives the child its URI and
   a random 64-byte key over stdin; the child connects back **twice**: a channel of messages and a channel
   of the `ExitResult`. The script of the child finds `autoload.php` by `dirname(__DIR__, 5)`, a PHAR is copied
   to a temporary directory, the xdebug ini settings are forwarded with `-d`, the child ignores SIGINT, SIGTERM
   and the other termination signals, its stdout and stderr are piped to the parent's by `ByteStream\pipe()`.
2. **Worker**: in the child, `task-runner.php` loops over `TaskSubmission`, `JobMessage`, `JobCancellation`.
   A task is a serializable object, `Task::run(Channel, Cancellation)`. The parent gets an `Execution`:
   a `Future` of the result and a `Channel` to the task. A failure comes back as `TaskFailureException`
   with the class, message and trace as strings. Several tasks may run in one worker at once,
   the pool gives it one at a time.
3. **Pool**: `ContextWorkerPool` with a limit, workers started lazily, a crashed one replaced, `shutdown()`
   and `kill()`, plus a global `workerPool()` per event loop driver in a `WeakMap`.

The background reading of a worker is `EventLoop::queue()`: nothing owns it.

## The transport: a socket pair, no hub

The hub, the key, the two connections and the connect timeout exist for Windows, where a child cannot inherit
an extra descriptor. We are Unix only (`agents/platform.md`): `socketPair()` of `net`, one end passed
to the child as `3 => $socket` in the descriptor spec of `proc_open()`, the child opens `php://fd/3`.
One socket per worker, nothing listens (no foreign connections to reject), no connect phase.

Messages and the result share the socket: a frame `[u32 length][u8 type][u64 job][payload]` on `Buffer`.

## The API: blocking calls, concurrency from async

The call blocks the fiber directly. Use `async` to run the call concurrently.

```php
$pool = new Pool(limit: 8, bootstrap: __DIR__.'/bootstrap.php'); // no I/O

group(static function (Group $group) use ($pool, $files) {
    $pool->run($group);

    $hashes = map($files, 8, static fn(string $file) => $pool->execute(hash_file(...), 'sha256', $file));

    $pool->close();
});
```

- `execute(callable $fn, mixed ...$args)`, the callable must be serializable (see "What a task is").
- A channel to the running task (`Execution::getChannel()`) waits for a real user: a request and a response
  is the common case. One worker runs one task at a time.

## What a task is: a named callable, not a serializable closure

Accepted:

- a first-class callable of a named function or a static method (`hash_file(...)`, `Thumbnails::make(...)`),
  sent as its name: no source is parsed, the child runs ordinary code from opcache;
- a string (`'hash_file'`) or `[Thumbnails::class, 'make']`, sent as is;
- a serializable object with `__invoke()`, instead of a `Task` interface.

An anonymous closure (`ReflectionFunction::isAnonymous()`) or one bound to an object is rejected with a clear
message; a rule of `phpstan-extension` may catch it at analysis time and infer the result from the signature.
Every argument is visible at the call: what crosses the process boundary is explicit.

Rejected: **serializable closures** (laravel/serializable-closure, used by amphp/parallel-functions;
opis/closure). They read the file and lines of the closure through reflection, cut its source out of the tokens,
serialize the `use` variables, `$this` and the scope, and compile the text again in the child (laravel includes it
through a `closure://` stream wrapper, an `eval` in effect):

- The state goes implicitly: whatever is captured (`use`, the auto-capture of `fn`, `$this` of a non-static
  closure) is serialized by value. A client with a socket, a `PDO`, an object holding a resource fails at runtime,
  at the first call; PHPStan does not see it.
- It looks like shared memory and is not: `use (&$results)`, `static` variables and globals change in the child's
  copy, the parent sees nothing, silently.
- The code comes from the source file, not from what was loaded: a file changed after loading (a deploy
  under a running process, `hot-reload`) gives the child another version. The parsing has limits: two closures
  on a line, `self::`/`static::`, a closure inside `eval`.
- Code compiled through `eval` or a stream wrapper skips opcache and is compiled at every load:
  noticeable for a pool that gets thousands of small tasks.
- A third-party PHP parser in a core package of an ecosystem built on thesis/async alone.

Security is not the reason: the socket pair is ours, an HMAC signature like laravel's is not needed.
If users need closures, a bridge over laravel/serializable-closure may turn one into a serializable invokable
object later, without changing the core.

## Cancellation: across the process boundary

In amphp `Execution::await($cancellation)` stops waiting while the task goes on. Ours:

- The fiber in `execute()` is cancelled: the parent sends a `Cancel` frame. In the child the call is a `Task`
  in a `group()`, cancelled by `Task::cancel()`, so `checkpoint()`, `isCancelled()` and `timeout()` work
  inside it.
- The parent waits for the answer under `shield()` and `timeout($cancelGrace)`; without one the process gets
  SIGKILL and is replaced. CPU-bound code that never calls `checkpoint()` ends this way.
- Invariant: **once `execute()` returns or throws, the call no longer runs in the child.**
- The deadline goes with the call (`remaining()`): the child scope inherits the caller's deadline, so
  `timeout(sec(5), fn() => $pool->execute(...))` needs no parameter of its own.
- Rejected: carrying `ScopedValue`s over: they are not serializable in general.

## The pool: a client of `agents/clients.md`

- `new Pool(...)` does no I/O; `run($group)` starts the background work (the reader of every worker,
  watching the processes) as daemons of `$group`. Workers start lazily, their daemons go to the same group.
- `close()` waits for the running calls and closes the sockets.
- Rejected: a global `workerPool()` per driver: its background work would have no owner.
- Rejected: reading through `EventLoop::queue()` (`ContextWorker`): the same reason.

## The life of a child

- EOF on the socket ends the child: if the parent dies, so do its children, no orphans.
- The child traps SIGINT and SIGTERM through `trapSignals()`: Ctrl+C goes to the whole process group,
  and stopping is the parent's job.
- A fatal error or `exit()` in the child: the parent sees EOF without a result, the caller gets `WorkerCrashed`
  with the exit code, the worker is replaced. A failure of the call itself does not kill the worker.

## Errors and serialization

- Native `serialize()`.
- The original exception class is not restored: its trace may hold closures, the class may not exist
  in the parent. `TaskFailed` carries the class, message, code, file, line, trace and the chain of `previous`.
- A result that cannot be serialized is a failure of the call, not of the worker.

## Starting the child

- The path to `vendor/autoload.php` is found by the parent through `ReflectionClass(ClassLoader::class)`
  and passed as an argument, no guessing by `dirname(..., 5)`.
- An optional `bootstrap` file, as in amphp.
- The binary is `PHP_BINARY`, the xdebug settings are forwarded with `-d` like amphp does.
- No PHAR in v1.

## stdout and stderr

The child inherits the parent's descriptors directly, no piping: this follows `io`, where stdio stays blocking
and shared with other processes.

The trap to check by a test on `DRIVER=Uv`: `uv_poll_init()` leaves `O_NONBLOCK` on the file description,
which the children share, so `echo` in a child may get EAGAIN and lose output. If it does, under uv the output
is piped through daemons of the pool.

## Not in v1

- **Threads of ext-parallel** (optional in the roadmap): a ZTS build, a resource cannot be passed to a thread
  (the hub would come back), amphp learns that a thread ended by polling every 250 ms. Decided by a benchmark.
- **Fork**: copies the state of the event loop, incompatible with ev and uv. Maybe for pre-fork in `cluster`.
- **Cross-process primitives** of `amphp/sync` (`PosixSemaphore`, `SharedMemoryParcel`): on request.

## Dependencies and order

`thesis/parallel` requires `async`, `io`, `net` (`socketPair()`), `time` and `process`, which comes first.
What `process` needs for it: passing arbitrary descriptors to the child, not only 0, 1 and 2, and inheriting stdio
without pipes.

## Open questions

1. A restart after N calls (`maxTasks`) against memory leaks? amphp has none.
2. Several calls at once in one worker: amphp can, its pool does not. One per worker is proposed.
3. The driver of `file` (question 5 of the roadmap): if it goes through `parallel`, the overhead of a call
   decides it, measured on the frame protocol above.
