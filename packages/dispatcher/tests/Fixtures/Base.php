<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Fixtures;

abstract class Base implements Event
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    final public function onSelf(self $event): void
    {
        $this->calls[] = $event::class;
    }
}
