# HTTP client

- Only return a persistent connection with an empty input buffer at a complete message boundary.
  Drop the body’s ownership before any later read, close or destruction can touch a reused socket.
- A response copy shares its reader. The reader that owns the lease cannot be cloned: two owners could
  release or close the same socket after another request has acquired it.
- Reserve idle sockets per scheme, host and effective port. Probe before reuse. EOF, pending data and
  transport errors retire an idle socket. A write or response failure never replays a request.
- A close-delimited response starts with any bytes the head reader already buffered.
- `ResponseBody::discard()` drains through `read()` to finish the lease. The chunked decoder then supplies its
  saved trailers. A close-delimited response returns empty headers without reading the released socket again.
