<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\SortAttributeNamedArgsRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\StringableForToStringRector;

$root = dirname(__DIR__);
/** @var array{optional: array<string, list<string>>, fixer: list<string>, rector: array<string, list<string>>} */
$manifests = require __DIR__ . '/manifests.php';
$skipped = array_map(
    static fn(array $paths) => array_map(static fn(string $path) => $root . '/' . $path, $paths),
    $manifests['rector'],
);

return RectorConfig
    ::configure()
    ->withPaths([
        $root . '/bin/monorepo',
        $root . '/monorepo.php',
        $root . '/tools',
        $root . '/packages',
    ])
    ->withParallel()
    ->withCache($root . '/var/rector')
    ->withPhpSets()
    ->withRules([
        SortCallLikeNamedArgsRector::class,
        SortAttributeNamedArgsRector::class,
    ])
    ->withSkip([
        StringableForToStringRector::class,
        // The fixtures call functions with named arguments out of order on purpose.
        SortCallLikeNamedArgsRector::class => [$root . '/packages/phpstan-extension/types'],
        // Generated, see php-cs-fixer.php.
        __DIR__ . '/manifests.php',
        ...$skipped,
    ])
;
