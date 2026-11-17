<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Fixtures;

abstract class Base implements Event
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * A closure keeps `self` as the name of its type, a method given as a callable gets the class instead.
     *
     * @return \Closure(self): void
     */
    final public static function selfListener(): \Closure
    {
        return static function (self $event) {};
    }

    final public function onSelf(self $event): void
    {
        $this->calls[] = $event::class;
    }
}
