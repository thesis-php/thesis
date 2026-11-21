<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;
use Thesis\TestoPlugin\Internal\VirtualTimeInterceptor;

/**
 * Runs the test, its hooks included, on a fresh virtual time: see `Thesis\Async\virtualTime()`.
 * On a class, the attribute applies to every test. Each data set gets its own clock.
 * For a whole suite, see {@see VirtualTimePlugin}.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
#[FallbackInterceptor(VirtualTimeInterceptor::class)]
final readonly class VirtualTime implements Interceptable {}
