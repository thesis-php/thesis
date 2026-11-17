<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Fixtures;

use Thesis\Dispatcher\Listener;

/**
 * The overridden methods are protected: the fixer makes every public method of an abstract class final.
 */
abstract class BaseSubscriber
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    #[Listener]
    final public function onInherited(Other $event): void
    {
        $this->calls[] = 'inherited';
    }

    // @phpstan-ignore thesis.listenerNotPublic (only the override is a listener)
    #[Listener]
    protected function onOverridden(Other $event): void
    {
        $this->calls[] = 'overridden in the base';
    }

    // @phpstan-ignore thesis.listenerNotPublic (the mark must not reach the override)
    #[Listener]
    protected function onUnmarked(Other $event): void
    {
        $this->calls[] = 'unmarked in the base';
    }
}
