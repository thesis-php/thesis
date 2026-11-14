<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // The amphp bridge is optional: amphp/amp comes with the amphp library it is used with, and its classes are loaded only when ampCancellable() is called.
    ->ignoreErrorsOnPackageAndPath('amphp/amp', __DIR__ . '/src/Internal/ScopeCancellation.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('amphp/amp', __DIR__ . '/src/functions.php', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
