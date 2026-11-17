<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

// The tests of a package installed alone by `make test-alone`, run in its copy with a vendor/ of its own.
// No PhpErrorsPlugin: thesis/testo-plugin requires the PHP of the monorepo.
$package = getcwd();
assert($package !== false);
$name = basename($package);
assert($name !== '');

return new ApplicationConfig(
    src: [$package . '/src'],
    suites: [new SuiteConfig(name: $name, location: [$package . '/tests'])],
);
