<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

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
