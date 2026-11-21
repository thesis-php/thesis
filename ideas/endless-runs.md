# Endless runs: ideas

This draft proposes a detector for code that holds the event loop indefinitely. Examples include an endless
loop, a huge `preg_match()` and a blocking call that hangs. `BlockingDetector` reports a run after it ends.
The proposed detector would report the run while it is still active and capture the stack of the code
holding the loop.

## Why not a thread

A watchdog thread (ext-parallel) sees that a heartbeat has stopped, and that is all: a thread is another PHP runtime,
it can neither take the backtrace of the main one nor interrupt it. It can only log or kill the process,
which a supervisor outside does as well. And it needs a ZTS build of PHP, which few have.

## How: a timer that interrupts the main thread

The timer of the OS fires, and a handler runs in the main thread at the next boundary of opcodes, on top of the code
that holds the loop:

1. A heartbeat: the last moment the loop has run (see "Open questions" for where it comes from).
2. Every `$interval` the handler compares `hrtime()` with the heartbeat. Older than `$threshold`: the loop is held,
   `debug_backtrace()` gives the stack of the fiber that holds it.
3. The report is written synchronously (a blocking write or a `callable` of the user): the loop is held,
   nothing that goes through it can run.

The sources of the timer:

- **ext-excimer** (`ExcimerTimer`, Wikimedia, PECL): an exact interval, the timer of the OS notifies its own
  thread in C (`SIGEV_THREAD`, kqueue on macOS), which only sets the VM interrupt flag. No signal reaches
  the main thread: no `EINTR` in the code it interrupts, no handler of a signal taken from the user.
  This is the "extra thread" done right: in C, sharing nothing with PHP.
- **`pcntl_alarm()` and `pcntl_signal(SIGALRM)`** with `pcntl_async_signals(true)`: no extension beyond pcntl,
  but whole seconds only, one alarm per process (it collides with the user's), and the signal breaks a syscall
  that is not restarted: `sleep()` and `usleep()` return early, so the detector changes the code it watches.

Trap: never `Loop::onSignal()` or `SignalTrap` for this. The loop runs their handlers between the fibers,
and the loop is the thing that is held. The same goes for the dump by SIGQUIT in `trace.md`: through `SignalTrap`
it shows a process stuck in waits (a deadlock), never one whose loop is held.

## What it costs

One timer and one comparison per `$interval`, no events of the trace: fit for production, unlike `BlockingDetector`,
which takes a backtrace at every wait.

## Limits

- A run inside one C function is seen only when it returns: the handler needs a boundary of opcodes.
- Real time only: under `virtualTime()` the heartbeat and the timer of the OS do not agree. A test needs
  a real loop that holds the event loop and a real timer, see `agents/tests.md` before writing one.
- The handler must not suspend, throw by accident or enter the loop: the rule of the listeners of `observe()`
  (`trace.md`), for the same reasons and more, since it runs in the middle of any opcode.

## Open questions

1. **The heartbeat.** The start of a step of `Internal\Loop`, a field: free while idle, sees the callbacks
   of the loop too, and the fiber of the step names the task that holds the loop (`ideas/loop.md`, the probe
   of the steps). Rejected: a repeating `watchdog()`, it wakes an idle process; the events of the trace,
   they need `observe()` and miss the code outside the tree.
2. **Throwing from the handler.** An exception thrown by a handler of `pcntl_signal()` (and of `ExcimerTimer`)
   comes out of the interrupted code: a deadline on CPU work. It breaks every invariant of the code it interrupts
   (a half-written buffer, a lock held), so probably rejected, at most a kill of the process after a report.
3. **Report once or again.** One report per run, or one per `$interval` with the stack each time: a stack that
   changes is a slow loop, one that does not is a stuck one.
4. **Where it lives.** thesis/async-trace next to `BlockingDetector`, with the report a noun like the others
   (not `Hang`/`Stall`, rejected in `packages/async-trace/AGENTS.md`). With the heartbeat of the loop it needs only
   thesis/async, which may argue for a package of its own.
5. **Outside the process.** reli-prof and phpspy read the PHP stack from the memory of another process: no code
   of ours, no extension in the watched process. A note in the docs, maybe enough for some users.
