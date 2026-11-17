<?php

declare(strict_types=1);

namespace Thesis\Dispatcher;

/**
 * Marks a public method as a listener for {@see listenersOf()}. The mark is not inherited:
 * an override without it is not a listener.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Listener {}
