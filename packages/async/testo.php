<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Thesis\Async\Testo\VirtualTimePlugin;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'All',
            location: ['tests'],
            plugins: [new VirtualTimePlugin()],
        ),
    ],
);
