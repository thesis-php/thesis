<?php

declare(strict_types=1);

use Time\Duration;

// Eagerly: Composer has no conditional autoload, and a PSR-4 entry for `Time\` would claim a namespace of PHP.
if (!class_exists(Duration::class, autoload: false)) {
    require_once dirname(__DIR__) . '/polyfill/TimeException.php';
    require_once dirname(__DIR__) . '/polyfill/Duration.php';
}
