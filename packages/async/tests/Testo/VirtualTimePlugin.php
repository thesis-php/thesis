<?php

declare(strict_types=1);

namespace Thesis\Async\Testo;

use Internal\Container\Container;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;
use Thesis\Async\Testo\Internal\VirtualTimeInterceptor;

/**
 * Runs every test of the suite as {@see VirtualTime} does. A test that has the attribute as well is not wrapped twice.
 */
final readonly class VirtualTimePlugin implements PluginConfigurator
{
    #[\Override]
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)->addInterceptor(VirtualTimeInterceptor::class);
    }
}
