# Thesis Demo

## Invariants that must not be broken

- Run the same entry point from the monorepo and the installed project. The split ships `run.php` through `dist`.
- Listen on loopback by default. Accept an explicit address for Docker port forwarding.
- The handler uses an async delay so the curl example can show waits overlapping.
- The HTTP server finishes before the logger drains. Both run in groups owned by the entry point.

## Decisions

- The entry point assembles the application with constructor calls. Rejected: a container, which would hide
  how to wire and start the Thesis components.
- The project installs the runtime Thesis dependency tree from `dev-main` so it shows the APIs on `main`.
