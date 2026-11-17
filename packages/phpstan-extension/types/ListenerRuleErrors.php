<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

use Thesis\Dispatcher;
use Thesis\Dispatcher\Listener;

/**
 * Not used: `make phpstan` reports every error that is not ignored and every ignore that matches no error,
 * so a line ignores an error exactly where the rule must report one.
 */
abstract class ListenerRuleErrors
{
    #[Listener]
    final public function isAListener(object $event): void {}

    #[Listener]
    final public static function isAStaticListener(object $event): void {}

    #[Listener]
    final public function takesTheDispatcher(object $event, Dispatcher $dispatcher, int $times = 1): void {}

    #[Listener]
    final public function takesAnyObject(object $event, ?object $dispatcher = null): void {}

    #[Listener]
    final public function takesAnything(object $event, mixed $dispatcher): void {}

    #[Listener]
    final public function takesDispatchers(object $event, Dispatcher ...$dispatchers): void {}

    #[Listener]
    final public function takesNoDispatcher(
        object $event,
        // @phpstan-ignore thesis.listenerSecondParameter (the case)
        int $times,
    ): void {}

    #[Listener]
    final public function takesNoDispatcherByDefault(
        object $event,
        // @phpstan-ignore thesis.listenerSecondParameter (the case)
        int $times = 1,
    ): void {}

    #[Listener]
    final public function takesMoreThanTheDispatcher(
        object $event,
        Dispatcher $dispatcher,
        // @phpstan-ignore thesis.listenerRequiredParameter (the case)
        int $times,
    ): void {}

    // @phpstan-ignore thesis.listenerNotPublic (the case)
    #[Listener]
    protected function isProtected(object $event): void {}

    // @phpstan-ignore thesis.listenerNotPublic, method.unused (the case)
    #[Listener]
    private static function isPrivate(object $event): void {}

    // @phpstan-ignore thesis.listenerAbstract (the case)
    #[Listener]
    abstract public function isAbstract(object $event): void;

    // @phpstan-ignore thesis.listenerAbstract, thesis.listenerNotPublic (the case)
    #[Listener]
    abstract protected function isAbstractAndProtected(object $event): void;
}

interface ListenerRuleInterfaceErrors
{
    // @phpstan-ignore thesis.listenerAbstract (the case)
    #[Listener]
    public function isInAnInterface(object $event): void;
}
