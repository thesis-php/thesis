<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$root = dirname(__DIR__);

return new Configuration()
    // The amphp bridge of async is optional: amphp/amp comes with the amphp library it is used with,
    // and its classes are loaded only when ampCancellable() is called.
    ->ignoreErrorsOnPackageAndPath(
        packageName: 'amphp/amp',
        path: $root . '/packages/async/src/Internal/ScopeCancellation.php',
        errorTypes: [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
    ->ignoreErrorsOnPackageAndPath(
        packageName: 'amphp/amp',
        path: $root . '/packages/async/src/functions.php',
        errorTypes: [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
    // ext-sockets is optional: it only explains why a connect failed, behind function_exists().
    ->ignoreErrorsOnExtensionAndPath(
        extension: 'ext-sockets',
        path: $root . '/packages/net/src/Internal/Connector.php',
        errorTypes: [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
    // ext-intl is optional: only internationalized host names need it, behind function_exists().
    ->ignoreErrorsOnExtensionAndPath(
        extension: 'ext-intl',
        path: $root . '/packages/net/src/Internal/functions.php',
        errorTypes: [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
;
