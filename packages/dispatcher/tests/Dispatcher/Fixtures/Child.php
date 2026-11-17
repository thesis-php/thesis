<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Fixtures;

final class Child extends Base implements Named
{
    /**
     * A closure keeps `parent` as the name of its type, a method given as a callable gets the class instead.
     *
     * @return \Closure(Base): void
     */
    public static function parentListener(): \Closure
    {
        return static function (parent $event) {};
    }

    public function onParent(parent $event): void
    {
        $this->calls[] = $event::class;
    }
}
