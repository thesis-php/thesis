<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$root = dirname(__DIR__);
$config = new Configuration();
// phpstan/phpstan is a phar, the analyser autoloads only its own classes, not nikic/php-parser bundled in it,
// which an extension of PHPStan uses.
$config->ignoreUnknownClassesRegex('~^PhpParser\\\~');
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
