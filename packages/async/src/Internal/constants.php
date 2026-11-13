<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\FiberLocal;

/**
 * Stands in for {main}, which has no \Fiber, as a key of {@see FiberLocal}. Never started.
 *
 * @internal
 */
const MAIN = new \Fiber(static function (): void {});
