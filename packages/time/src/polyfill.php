<?php

declare(strict_types=1);

use Time\Duration;

// The polyfill is loaded eagerly: Composer has no conditional autoload, and a PSR-4 entry for a namespace
// of PHP itself is not ours to declare. On PHP 8.6 the classes exist and nothing is loaded.
if (!class_exists(Duration::class, autoload: false)) {
    require_once dirname(__DIR__) . '/polyfill/TimeException.php';
    require_once dirname(__DIR__) . '/polyfill/Duration.php';
}
