<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;
use Thesis\TestoPlugin\Internal\VirtualTimeInterceptor;

/**
 * Runs the test, its hooks included, on a fresh virtual time: see `Thesis\Async\VirtualTime\run()`.
 * On a class, every test of it; every data set gets a clock of its own.
 * For a whole suite, see {@see VirtualTimePlugin}.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
#[FallbackInterceptor(VirtualTimeInterceptor::class)]
final readonly class VirtualTime implements Interceptable {}
