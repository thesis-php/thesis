<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;
use Thesis\TestoPlugin\Internal\EventLoopInterceptor;

/**
 * Runs the test, its hooks included, in the event loop of thesis/async on the real clock, as the body
 * of a `Thesis\Async\group()`: outside a run of the loop a wait throws `LogicException`.
 * On a class, the attribute applies to every test. See {@see EventLoopPlugin} for a whole suite.
 * For virtual time, see {@see VirtualTime}.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
#[FallbackInterceptor(EventLoopInterceptor::class)]
final readonly class EventLoop implements Interceptable {}
