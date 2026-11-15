# splitsh-lite links against libgit2 through git2go v34, which needs libgit2 1.5 exactly: Debian bookworm has it,
# the Ubuntu of GitHub runners does not.
FROM golang:1.22-bookworm AS build
RUN apt-get update \
    && apt-get install --yes --no-install-recommends libgit2-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*
ARG SPLITSH_COMMIT=36e0e74cfef34487bf15f0bbaff7260b9ce9c021
RUN git clone https://github.com/splitsh/lite /src \
    && git -C /src checkout "$SPLITSH_COMMIT" \
    && cd /src \
    && go build -o /splitsh-lite .

FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install --yes --no-install-recommends ca-certificates git libgit2-1.5 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /splitsh-lite /usr/local/bin/splitsh-lite
ENV HOME=/tmp
