<?php

declare(strict_types=1);

namespace Thesis\SourceLocation;

/**
 * A call made from outside a library: where it is written and what it calls.
 *
 * @api
 */
final readonly class Caller
{
    /**
     * @param non-empty-string $callee the function or the method called, like `Thesis\Async\delay`
     *     or `App\Client::send`, a closure as PHP names it (`{closure:App\Client::send():12}`)
     */
    public function __construct(
        public Location $location,
        public string $callee,
    ) {}

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->callee . '() at ' . $this->location;
    }
}
