<?php

declare(strict_types=1);

use Time\Duration;

// Eagerly: with a classmap, PHPStan on PHP 8.6 would take the polyfill for the class of PHP.
if (!class_exists(Duration::class, autoload: false)) {
    require_once __DIR__ . '/TimeException.php';
    require_once __DIR__ . '/Duration.php';
}
