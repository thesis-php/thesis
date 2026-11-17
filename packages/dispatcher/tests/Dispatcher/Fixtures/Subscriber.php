<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Fixtures;

use Thesis\Dispatcher\Listener;

final class Subscriber extends BaseSubscriber
{
    #[Listener]
    public function onOther(Other $event): void
    {
        $this->calls[] = 'other';
    }

    public function notAListener(Other $event): void
    {
        $this->calls[] = 'not a listener';
    }

    #[Listener]
    public function onUnrelated(Unrelated $event): void
    {
        $this->calls[] = 'unrelated';
    }

    #[Listener]
    #[\Override]
    public function onOverridden(Other $event): void
    {
        $this->calls[] = 'overridden';
    }

    /**
     * Not marked: the mark of the base is not inherited.
     */
    #[\Override]
    public function onUnmarked(Other $event): void
    {
        $this->calls[] = 'unmarked';
    }
}
