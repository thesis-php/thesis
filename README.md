# Thesis

An ecosystem of asynchronous PHP built on [revolt/event-loop](https://github.com/revoltphp/event-loop).

This is the development monorepo. Every package in `packages/` is published as a read-only `thesis/<name>` repository.

## Development

Everything runs in Docker, see `make help`.

```shell
make i                 # install dependencies
make test P=async      # test one package
make check             # everything CI runs
```

`AGENTS.md` describes the layout, the manifests and the rules of the monorepo.
