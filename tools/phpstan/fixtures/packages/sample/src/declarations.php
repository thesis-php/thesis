<?php

declare(strict_types=1);

namespace Thesis\Sample {
    /**
     * Mentions @api in prose.
     */
    // @phpstan-ignore thesis.packageApi (prose does not mark an interface as public)
    interface MissingTag {}

    /**
     * @api
     * @internal
     */
    // @phpstan-ignore thesis.packageApi (the declaration has both tags)
    enum ConflictingTags {}

    /**
     * @internal
     */
    // @phpstan-ignore thesis.packageApi (the function is outside the internal namespace)
    function wrongNamespace(): void {}

    /**
     * @api
     */
    const FIRST = 1, SECOND = 2;
}

namespace Thesis\Sample\Internal\Nested {
    /**
     * @api
     */
    // @phpstan-ignore thesis.packageApi (the internal constant is marked public)
    const WRONG_TAG = true;

    /**
     * @internal
     */
    final class Implementation {}
}

namespace Thesis\Sample\INTERNAL {
    /**
     * @api
     */
    // @phpstan-ignore thesis.packageApi (namespace names are case-insensitive)
    const MIXED_CASE = true;
}
