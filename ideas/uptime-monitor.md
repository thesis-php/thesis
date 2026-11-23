# Uptime monitor

Build a small Thesis application inspired by the monitor in Hardcourse Async PHP 2. A background task
periodically checks HTTP targets. A browser page shows the latest status, response time, last check time
and a short history per target. Publish a snapshot when the page connects, then stream each finished
check over Server-Sent Events (SSE).

Start with three local targets that work out of the box. One returns HTTP 200, one returns HTTP 503,
and one exceeds the check deadline. Accept extra URLs at startup. Keep the last few results per target
in memory and wire the server, HTTP client, check loop and console logger by hand.

Use bounded concurrency and a deadline per check so a slow target cannot hold back results from other
targets. On shutdown, stop scheduling checks, cancel pending work, close the SSE streams and drain
the logger. Adding and removing targets from the page can be a later step.
