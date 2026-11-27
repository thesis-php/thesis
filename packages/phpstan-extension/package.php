<?php

declare(strict_types=1);

use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Thesis\Monorepo\Manifest;
use Thesis\Monorepo\RectorSkip;

return new Manifest(
    description: 'PHPStan extension for thesis packages: precise types where PHPDoc generics cannot express them.',
    licenseSince: 2026,
    version: '0.1',
    title: 'Thesis PHPStan Extension',
    keywords: ['php', 'phpstan', 'phpstan-extension', 'static-analysis'],
    require: [
        'phpstan/phpstan' => '^2.3',
    ],
    requireDev: [
        'thesis/async' => '*',
        'thesis/time' => '*',
    ],
    skip: [
        new RectorSkip(
            rule: StringClassNameToClassConstantRector::class,
            paths: ['src'],
            reason: 'A class of a package is recognized by its name: the extension does not require the packages it knows.',
        ),
    ],
    dist: ['extension.neon'],
    type: 'phpstan-extension',
    // phpstan/extension-installer includes the configs of an installed package of type phpstan-extension
    extra: ['phpstan' => ['includes' => ['extension.neon']]],
);
