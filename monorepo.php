<?php

declare(strict_types=1);

use Thesis\Monorepo\Config;

/** Source of truth for the root composer.json together with packages/*\/package.php. Run `make sync` after a change. */
return new Config(
    php: '^8.5',
    version: '^2.0',
    description: 'Thesis: an asynchronous PHP ecosystem',
    copyrightHolder: 'The Thesis Authors',
    licenseSince: 2026,
    authors: [
        ['name' => 'Vadim Zanfir', 'email' => 'vadimzanfir@gmail.com'],
        ['name' => 'Valentin Udaltsov', 'email' => 'udaltsov.valentin@gmail.com'],
        ['name' => 'Contributors', 'homepage' => 'https://github.com/thesis-php/thesis/graphs/contributors'],
    ],
    platform: [
        'php-64bit' => '*',
    ],
    requireDev: [
        'composer/semver' => '^3.4',
        'llm/skills' => '^1.17',
        'symfony/console' => '^8.0',
        'symfony/var-dumper' => '^8.0',
        'testo/assert' => '^0.1.17',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    autoloadDev: [
        'Thesis\Monorepo\\' => 'tools/monorepo/src/',
        'Thesis\Monorepo\Tests\\' => 'tools/monorepo/tests/',
        'Thesis\PhpCsFixer\Tests\\' => 'tools/php-cs-fixer/tests/',
    ],
    allowPlugins: [
        'llm/skills' => true,
    ],
);
