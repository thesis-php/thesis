# HTTP client

- The returned reader owns the socket. A clone shares the reader. Release the socket on explicit close, at the body boundary,
  on permanent I/O failure, or when the last reference to an unfinished reader goes away.
- Use the shared HTTP/1 codec for both heads and body boundaries. A close-delimited response starts with
  any bytes the head reader already buffered.
