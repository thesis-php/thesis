<?php

declare(strict_types=1);

namespace Thesis\Process;

/**
 * Exactly one property has a value: the exit code or the signal that killed the child.
 *
 * @api
 */
final readonly class ExitStatus
{
    /**
     * @internal
     */
    public function __construct(
        public ?int $code,
        public ?int $signal,
    ) {}
}
