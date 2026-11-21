<?php

declare(strict_types=1);

namespace Thesis\Cache\Internal;

/**
 * The cache uses this default for get() to distinguish a miss from any stored value, including null.
 *
 * @internal
 */
enum Missing
{
    case Value;
}
