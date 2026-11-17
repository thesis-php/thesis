<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\Tools;
use Thesis\TestoPlugin\PhpErrorsPlugin;

$tools = Tools::of(Monorepo::load(__DIR__));

return new ApplicationConfig(
    src: $tools->testoSrc,
    suites: $tools->testoSuites,
    // The plugins of every suite, those of one package are in its manifest.
    plugins: [new PhpErrorsPlugin()],
);
