# Thesis Demo

## Invariants that must not be broken

- Run the same entry point from the monorepo and the installed project. The split ships `app.php` and `index.html` through `dist`.
- Listen on loopback by default. Accept an explicit address for Docker port forwarding.
- Read `index.html` once at startup. The delayed route uses an async delay so curl can show waits overlapping.
- The HTTP server finishes before the logger drains. Construct and run both inside the entry point’s group. The logger reports the listening address at startup.

## Decisions

- The entry point assembles the application with constructor calls. Rejected: a container, which would hide
  how to wire and start the Thesis components.
- The project installs its runtime and development Thesis dependencies from `dev-main` so it shows the APIs on `main`.
