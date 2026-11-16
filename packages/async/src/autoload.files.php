<?php

declare(strict_types=1);

require_once __DIR__ . '/Internal/fiberKey.php';
require_once __DIR__ . '/Internal/holding.php';
require_once __DIR__ . '/Internal/some.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/VirtualTime/functions.php';

if (PHP_OS_FAMILY === 'Windows') {
    trigger_error(
        message: 'thesis does not support Windows, nothing is guaranteed to work. Need it? Open an issue: https://github.com/thesis-php/thesis/issues',
        error_level: E_USER_WARNING,
    );
}
