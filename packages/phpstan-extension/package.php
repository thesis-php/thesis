<?php

declare(strict_types=1);

use Thesis\Monorepo\Manifest;

return new Manifest(
    description: 'PHPStan extension for thesis packages: precise types where PHPDoc generics cannot express them.',
    licenseSince: 2026,
    require: [
        'phpstan/phpstan' => '^2.3',
    ],
    requireDev: [
        'thesis/async' => '*',
    ],
    dist: ['extension.neon'],
    type: 'phpstan-extension',
    // phpstan/extension-installer includes the configs of an installed package of type phpstan-extension
    extra: ['phpstan' => ['includes' => ['extension.neon']]],
);
