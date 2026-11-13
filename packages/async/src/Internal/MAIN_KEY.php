<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\FiberLocal;

/**
 * The key of {main}, which has no \Fiber, in {@see FiberLocal}. Not a fiber of {main}: it is never started,
 * it is a \Fiber only so that the keys of FiberLocal have one type.
 *
 * @internal
 */
const MAIN_KEY = new \Fiber(static function (): void {});
