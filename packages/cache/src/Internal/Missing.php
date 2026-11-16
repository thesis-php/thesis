<?php

declare(strict_types=1);

namespace Thesis\Cache\Internal;

/**
 * The default of get() that tells a miss from any stored value, null included.
 *
 * @internal
 */
enum Missing
{
    case Value;
}
