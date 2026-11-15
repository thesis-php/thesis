#!/bin/sh
# Splits every packages/<name> of a branch or a v* tag and pushes it to the same ref of thesis-php/<name>.
# Without SPLIT_REMOTE (https://x-access-token:<token>@github.com/thesis-php) it only prints the split commits.
set -eu

ref=${1:?usage: split.sh <ref>, e.g. refs/heads/main or refs/tags/v0.1.3}
remote=${SPLIT_REMOTE:-}

git rev-parse --verify --quiet "$ref^{commit}" > /dev/null || {
    echo "$ref is not a ref of this repository" >&2
    exit 1
}

# A tag goes only to the packages that changed since the previous tag of the same minor series: the first tag
# of a series (v0.2.0) goes to all of them, so that ^0.2 resolves for every package.
previous=
case $ref in
    refs/heads/*) ;;
    refs/tags/v*)
        tag=${ref#refs/tags/}
        previous=$(git -c versionsort.suffix=- tag --list 'v*' --merged "$ref" --sort=-v:refname | grep -vxF "$tag" | head -n 1 || true)
        if [ "${previous%.*}" != "${tag%.*}" ]; then
            previous=
        fi
        ;;
    *)
        echo "Only branches and v* tags are split, got $ref" >&2
        exit 1
        ;;
esac

for prefix in $(git ls-tree -d --name-only "$ref" packages/); do
    name=${prefix#packages/}

    if [ -n "$previous" ] && [ "$(git rev-parse --verify --quiet "$previous:$prefix" || true)" = "$(git rev-parse "$ref:$prefix")" ]; then
        echo "$name: unchanged since $previous, skipped"
        continue
    fi

    # The cache of splitsh-lite is keyed by the name of the ref: a ref moved back gets the split of its old commit.
    commit=$(splitsh-lite --scratch --prefix="$prefix" --origin="$ref")

    if [ -z "$remote" ]; then
        echo "$name: $commit"
    else
        git push "$remote/$name.git" "$commit:$ref"
    fi
done
