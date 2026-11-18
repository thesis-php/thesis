<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Internal\Container\Container;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;
use Thesis\TestoPlugin\Internal\EventLoopInterceptor;

/**
 * Runs every test of the suite as {@see EventLoop} does. A test that has the attribute as well is not wrapped twice.
 *
 * @api
 */
final readonly class EventLoopPlugin implements PluginConfigurator
{
    #[\Override]
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)->addInterceptor(EventLoopInterceptor::class);
    }
}
