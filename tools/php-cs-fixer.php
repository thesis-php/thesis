<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use PHPyh\CodingStandard\PhpCsFixerCodingStandard;

$root = dirname(__DIR__);

$config = new Config()
    ->setFinder(
        Finder::create()
            ->in([$root . '/tools', $root . '/packages'])
            ->append([
                $root . '/bin/monorepo',
                $root . '/monorepo.php',
            ]),
    )
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile($root . '/var/php-cs-fixer.cache');

new PhpCsFixerCodingStandard()->applyTo($config);

return $config;
