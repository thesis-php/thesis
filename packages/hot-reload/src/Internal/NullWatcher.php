<?php

declare(strict_types=1);

namespace Thesis\HotReload\Internal;

use Thesis\Async\Group;

/**
 * @internal
 */
final class NullWatcher implements Watcher
{
    public function run(Group $group): void {}

    public function refresh(): void {}
}
