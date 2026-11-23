# thesis/http-server

## Invariants that must not be broken

- One buffer belongs to the connection. Finish writing the response before checking whether the incoming body
  ended. An echo response can consume the request reader after the handler returns.
- Reuse requires zero remaining bytes for fixed-length input or ready trailers for chunked input.
- A final response head commits the response. If writing the body or its trailers fails, close the connection.
  A second status line would become part of the first response.
- A timeout on the whole request head includes every fragment. The body idle timeout starts anew on each
  transport read, including reads of chunk framing. A keep-alive wait expires silently before the next request.
- Each accepted socket occupies one slot until it closes. Shutdown must wake the supervisor even when
  the accept loop is waiting for a slot. That is why it waits on `Net\Server::awaitClose()`.

## Decisions

- The constructor takes the configuration and handler. Bind the endpoint in `run()` so a DI container can build the server
  without opening sockets and the application sees a bind failure at startup.
- Like the AMQP client, the server starts a daemon in the group passed to `run()`. It returns once the daemon
  has registered shutdown. A handler may call `close()`: the group joins active exchanges after it returns.

- The listener owns its sockets and drains active exchanges on shutdown. `Connection::serve()` also works
  with a caller-provided reader and writer; that caller closes its transport in a finally block.
- The keep-alive read can receive a whole head under the idle timeout. Keep bytes after its delimiter
  for the body or next request. Only a head that still needs bytes needs a head timer.
- Write a small string body with its response head. Reading a stream can wait for the peer to receive
  the head, so send the head before reading a streaming body. A small fixed-length echo can share
  that write when the connection already has all its remaining input. A large string is already in memory:
  send it in blocks of up to 64 KiB to amortize the socket write and its timer. Keep streaming reads at 8 KiB.
- Close after an unread body. Draining it would compete with any outstanding application read and would need
  a separate size limit and deadline.
