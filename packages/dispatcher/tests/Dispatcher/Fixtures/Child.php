<?php

declare(strict_types=1);

namespace Thesis\Dispatcher\Fixtures;

final class Child extends Base implements Named
{
    public function onParent(parent $event): void
    {
        $this->calls[] = $event::class;
    }
}
