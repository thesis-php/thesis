<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\Tools;

return Tools
    ::of(Monorepo::load(__DIR__))
    ->ignoreOptionalDependencies(new Configuration())
    // An extension of PHPStan uses nikic/php-parser bundled in the phar of phpstan/phpstan. The analyser finds
    // its classes in the copy that infection/infection installs and takes them for a dependency that is not listed.
    ->ignoreErrorsOnPackageAndPath(
        packageName: 'nikic/php-parser',
        path: __DIR__ . '/packages/phpstan-extension/src',
        errorTypes: [ErrorType::SHADOW_DEPENDENCY],
    )
;
