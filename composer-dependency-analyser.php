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
    ->ignoreErrorsOnPackageAndPaths(
        packageName: 'nikic/php-parser',
        paths: [__DIR__ . '/packages/phpstan-extension/src', __DIR__ . '/tools/phpstan/src'],
        errorTypes: [ErrorType::SHADOW_DEPENDENCY],
    )
    // thesis/time takes \RoundingMode from it below PHP 8.4, where the analyser runs the class is native.
    ->ignoreErrorsOnPackage('symfony/polyfill-php84', [ErrorType::UNUSED_DEPENDENCY])
    // thesis/exceptionally takes get_error_handler() from it below PHP 8.5, likewise.
    ->ignoreErrorsOnPackage('symfony/polyfill-php85', [ErrorType::UNUSED_DEPENDENCY])
;
