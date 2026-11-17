<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\ToolSkips;

$config = new Configuration();
// An extension of PHPStan uses nikic/php-parser bundled in the phar of phpstan/phpstan. The analyser finds
// its classes in the copy that infection/infection installs and takes them for a dependency that is not listed.
$config->ignoreErrorsOnPackageAndPath(
    packageName: 'nikic/php-parser',
    path: __DIR__ . '/packages/phpstan-extension/src',
    errorTypes: [ErrorType::SHADOW_DEPENDENCY],
);

foreach (ToolSkips::of(Monorepo::load(__DIR__))->optional as $path => $dependencies) {
    foreach ($dependencies as $dependency) {
        if (str_starts_with($dependency, 'ext-')) {
            $config->ignoreErrorsOnExtensionAndPath($dependency, $path, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
        } else {
            $config->ignoreErrorsOnPackageAndPath($dependency, $path, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
        }
    }
}

return $config;
