<?php

declare(strict_types=1);

return Rector\Config\RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withParallel()
    ->withCache(__DIR__ . '/var/rector')
    ->withPhpSets()
    ->withSkip([
        Rector\Php80\Rector\Class_\StringableForToStringRector::class,
        // Promotion would make the property positive-int, like the parameter, but it goes down to 0.
        Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/src/LocalSemaphore.php',
        ],
    ]);
