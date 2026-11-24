<?php

declare(strict_types=1);

namespace Thesis\HotReload\Internal;

use Thesis\Async\Group;

/**
 * @internal
 */
interface Watcher
{
    public function run(Group $group): void;

    /**
     * Acknowledge changes covered by the next launch without suspending.
     */
    public function refresh(): void;
}
