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

# A stable tag goes only to the packages that changed since the previous stable tag of the same minor series.
# Every other tag goes to all packages: the first tag of a series (v2.1.0), so that ^2.1 resolves for each of them,
# and a pre-release or the first stable tag after one (v2.0.0-beta1, v2.0.0), so that no package is left
# with a less stable version than the others.
previous=
case $ref in
    refs/heads/*) ;;
    refs/tags/v*)
        tag=${ref#refs/tags/}
        previous=$(
            git -c versionsort.suffix=- tag --list 'v*' --merged "$ref" --sort=-v:refname \
                | grep -vxF "$tag" \
                | head -n 1 \
                || true
        )
        case "$tag $previous" in
            *-*) previous= ;;
        esac
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

    if [ -n "$previous" ] \
        && [ "$(git rev-parse --verify --quiet "$previous:$prefix" || true)" = "$(git rev-parse "$ref:$prefix")" ]; then
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
