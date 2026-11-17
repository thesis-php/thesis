<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Internal\Container\Container;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;
use Thesis\TestoPlugin\Internal\PhpErrorsInterceptor;

/**
 * Throws a PHP error that a test does not suppress (a warning, a notice, a deprecation) as \ErrorException
 * where it happens, in the test and in the fibers it starts alike: otherwise PHP only prints it, and the test passes.
 *
 * @api
 */
final readonly class PhpErrorsPlugin implements PluginConfigurator
{
    #[\Override]
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)->addInterceptor(PhpErrorsInterceptor::class);
    }
}
