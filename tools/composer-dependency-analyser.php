<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$root = dirname(__DIR__);
$config = new Configuration();
// An extension of PHPStan uses nikic/php-parser bundled in the phar of phpstan/phpstan. The analyser finds
// its classes in the copy that infection/infection installs and takes them for a dependency that is not listed.
$config->ignoreErrorsOnPackageAndPath(
    packageName: 'nikic/php-parser',
    path: $root . '/packages/phpstan-extension/src',
    errorTypes: [ErrorType::SHADOW_DEPENDENCY],
);
/** @var array{optional: array<string, list<string>>, fixer: list<string>, rector: array<string, list<string>>} */
$manifests = require __DIR__ . '/manifests.php';

foreach ($manifests['optional'] as $path => $dependencies) {
    $path = $root . '/' . $path;

    foreach ($dependencies as $dependency) {
        if (str_starts_with($dependency, 'ext-')) {
            $config->ignoreErrorsOnExtensionAndPath($dependency, $path, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
        } else {
            $config->ignoreErrorsOnPackageAndPath($dependency, $path, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
        }
    }
}

return $config;
