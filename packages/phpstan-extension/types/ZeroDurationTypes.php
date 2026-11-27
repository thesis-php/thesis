<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

use Time\Duration;
use function PHPStan\Testing\assertType;
use const Thesis\Time\ZERO as NO_DELAY;

const ZERO = 0;

function zeroDurationHasObjectType(): void
{
    assertType(Duration::class, NO_DELAY);
    assertType(Duration::class, \Thesis\Time\ZERO);
    assertType('0', ZERO);
}
