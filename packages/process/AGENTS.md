# Process

## Invariants that must not be broken

- Amp and Symfony Process are development references. The runtime implementation uses Thesis packages.
- Subscribe one shared SIGCHLD callback per event loop before spawning. A signal can cover several exits.
  Peek with `pcntl_waitid(P_ALL, ..., WEXITED | WNOHANG | WNOWAIT)`, then retrieve the status through the
  managed child's own `proc_get_status()` handle. Index managed children by PID across groups.
  A foreign waitable child can hide other exits behind it. Leave its status to its owner and check the managed
  handles in the receiver. Save the first terminal status before closing the process: exit(143) and SIGTERM differ.
- Release each subscription in its owning loop. The last release restores the previous signal handler.
  Closing a group kills and reaps only its workers.
- A worker must receive EOF when the last owner closes the other end of its socket. Mask unrelated inherited
  descriptors, including the other end of the worker's own socket and the sockets of its siblings.
  Opening `php://fd/3` duplicates fd 3. Closing that PHP stream leaves the inherited descriptor open until exit.
- PHP 8.6 remaps descriptors in descriptor-spec order. When a target overlaps a temporary source for a later
  mapping, it can overwrite that source. The sparse-descriptor test checks that each target gets its own stream.
  Scanning and spawning must run together without suspension or a stream wrapper that opens another descriptor.

## Decisions

- Rejected: a SIGCHLD trap per child. Exiting one worker in a pool of eight resumed all eight watcher fibers.
  One trap per group still woke unrelated pools.
- Rejected: passing the PID from `siginfo` through the loop to handle the first exit. When 12 children exited
  under one pending SIGCHLD, `siginfo` identified one and `waitid()` found the other 11. For isolated exits,
  using that PID cut `waitid()` calls from two to one. On Linux with PHP 8.6 and 64 workers, we measured from
  SIGKILL to the return of `wait()` in 12 paired runs of 128 exits. In one group, median parent CPU time fell
  from 46 to 45 µs and median elapsed time from 160.1 to 159.0 µs. With one group per worker, CPU stayed at
  45 µs and elapsed time rose from 158.3 to 159.6 µs. The extra signal handling bought no consistent latency gain.
- Target Unix. Workers in the parallel design need to inherit stdio and receive an extra descriptor for IPC.
