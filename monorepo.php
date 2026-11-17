<?php

declare(strict_types=1);

use Thesis\Monorepo\Config;

/** Source of truth for the root composer.json together with packages/*\/package.php. Run `make sync` after a change. */
return new Config(
    php: '^8.5',
    description: 'Asynchronous PHP on Revolt: structured concurrency for fibers, non-blocking I/O, TCP/TLS/DNS, AMQP. '
        . 'Monorepo of the thesis/* packages.',
    copyrightHolder: 'The Thesis Authors',
    licenseSince: 2026,
    keywords: [
        'php',
        'async',
        'asynchronous',
        'fibers',
        'event-loop',
        'revolt',
        'structured-concurrency',
        'concurrency',
        'non-blocking-io',
        'monorepo',
        'amqp',
        'rabbitmq',
        'sockets',
        'dns',
    ],
    authors: [
        ['name' => 'Vadim Zanfir', 'email' => 'vadimzanfir@gmail.com'],
        ['name' => 'Valentin Udaltsov', 'email' => 'udaltsov.valentin@gmail.com'],
        ['name' => 'Contributors', 'homepage' => 'https://github.com/thesis-php/thesis/graphs/contributors'],
    ],
    platform: [
        'php-64bit' => '*',
    ],
    requireDev: [
        'ext-tokenizer' => '*',
        'ext-zlib' => '*',
        'composer/semver' => '^3.4',
        'infection/infection' => '^0.35.10',
        'llm/skills' => '^1.17',
        'symfony/console' => '^8.0',
        'symfony/var-dumper' => '^8.0',
        'testo/assert' => '^0.1.17',
        'testo/bridge-infection' => '^0.1.9',
        'testo/test' => '^0.1.8',
        'testo/testo' => '^0.10.29',
    ],
    autoloadDev: [
        'Thesis\Monorepo\\' => 'tools/monorepo/src/',
        'Thesis\Monorepo\Tests\\' => 'tools/monorepo/tests/',
        'Thesis\PhpCsFixer\Tests\\' => 'tools/php-cs-fixer/tests/',
        'Thesis\TraceRecorder\\' => 'tools/trace-recorder/src/',
        'Thesis\TraceRecorder\Tests\\' => 'tools/trace-recorder/tests/',
    ],
    allowPlugins: [
        'infection/extension-installer' => true,
        'llm/skills' => true,
    ],
);
