<?php

declare(strict_types=1);

use Tally\Configuration;

// The environment is the defaults of Tally and the driver that `make bench DRIVER=...` chooses: the others
// are left out, because they move the memory layout of the counted process.
return Configuration
    ::create()
    ->withPaths([dirname(__DIR__) . '/packages'])
    ->withEnvironmentVariables([
        'PATH',
        'HOME',
        'TMPDIR',
        'LANG',
        'LC_ALL',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
        'REVOLT_DRIVER',
    ])
;
