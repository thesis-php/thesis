# HTTP client

- Only return a persistent connection with an empty input buffer at a complete message boundary.
  Drop the body’s ownership before any later read, close or destruction can touch a reused socket.
- Response copies share one body handoff. The reader that owns the lease cannot be cloned: two owners could
  release or close the same socket after another request has acquired it.
- Reserve idle sockets per scheme, host and effective port. Probe before reuse. EOF, pending data and
  transport errors retire an idle socket. The transport leaves retry decisions to middleware.
- A close-delimited response starts with any bytes the head reader already buffered.
- Reading through EOF finishes the lease. Reading trailers delegates to the underlying body and never touches
  a released socket. The transport takes the upload once and closes it on every exit.
- A per-origin permit covers dialing, sending and the lifetime of the response body. Release it on every exit,
  including cancellation, early close and destruction. Reuse the most recently idle socket for an origin and
  evict the oldest idle socket across all origins at capacity.
- Build the middleware chain once per client. A handler outside redirects sees one application request.
  A handler inside redirects sees each destination. Credential middleware must check that destination.
- Resolve Location against the current URI. Drop private and unrecognized fields across origins and refuse HTTPS
  downgrades. Return the last response when following would require replaying a one-shot body.
- Retry only by explicit policy. Idempotence and a `ReplayableBody` are both required. After returning the
  response head, a download failure belongs to the caller.
- Decode only the encoding the middleware negotiated. Keep explicit negotiation, partial responses and
  bodyless representation metadata intact. Limit compressed input per inflate step and verify the stream end.
- A proxy connector owns its CONNECT headers. The TLS peer name after CONNECT comes from the origin.
- A multipart upload reads parts in order. Its total size includes boundaries and headers and is known only
  when every part has a known length. Keep one-shot readers out of automatic replay.
