# Thesis Demo

## Invariants that must not be broken

- Run the same entry point from the monorepo and the installed project. The split ships `app.php`, `index.html` and `tls/` through `dist`.
- Bind fixed HTTP and HTTPS ports on all IPv4 interfaces so Docker can forward both.
- HTTP and HTTPS start and stop together. Use the bundled localhost certificate and key.
- Read `index.html` once at startup. The delayed route uses an async delay so curl can show waits overlapping.
- The HTTP server finishes before the logger drains. Construct both before entering the group. The group owns startup and shutdown. The logger reports the listening address at startup.

## Decisions

- The entry point assembles the application with constructor calls. Rejected: a container, which would hide
  how to wire and start the Thesis components.
- The project installs its runtime and development Thesis dependencies from `dev-main` so it shows the APIs on `main`.
