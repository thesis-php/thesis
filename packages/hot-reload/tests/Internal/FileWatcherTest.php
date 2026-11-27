<?php

declare(strict_types=1);

namespace Thesis\HotReload\Internal;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Channel;
use Thesis\Async\Group;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\virtualTime;
use function Thesis\exceptionally;
use function Thesis\Time\ms;
use const Thesis\Time\ZERO;

#[Test]
final class FileWatcherTest
{
    public function requestsOneReloadAfterSavesAcrossTwoPolls(): void
    {
        $file = exceptionally(static fn() => tempnam(sys_get_temp_dir(), 'thesis-hot-reload-'));

        try {
            virtualTime(static fn() => group(static function (Group $group) use ($file) {
                /** @var Channel<true> $reload */
                $reload = new Channel(capacity: 2);
                $watcher = new FileWatcher([$file], ms(350), $reload);
                $watcher->run($group);
                $watcher->refresh();

                exceptionally(static fn() => file_put_contents($file, 'first'));
                delay(ms(250));
                Assert::null($reload->tryReceive());

                exceptionally(static function () use ($file) {
                    file_put_contents($file, 'second');
                    file_put_contents($file, 'last');
                });
                delay(ms(200));
                Assert::null($reload->tryReceive());

                delay(ms(200));
                Assert::null($reload->tryReceive());

                delay(ms(125));
                Assert::same($reload->tryReceive(), true);

                delay(ms(400));
                Assert::null($reload->tryReceive());

                exceptionally(static fn() => file_put_contents($file, 'edit before a manual restart'));
                delay(ms(200));
                $watcher->refresh();

                delay(ms(400));
                Assert::null($reload->tryReceive());
            }));
        } finally {
            @unlink($file);
        }
    }

    public function rechecksFilesWhenDebounceEndsBeforeTheNextPoll(): void
    {
        $file = exceptionally(static fn() => tempnam(sys_get_temp_dir(), 'thesis-hot-reload-'));

        try {
            virtualTime(static fn() => group(static function (Group $group) use ($file) {
                /** @var Channel<true> $reload */
                $reload = new Channel(capacity: 2);
                $watcher = new FileWatcher([$file], ms(50), $reload);
                $watcher->run($group);
                $watcher->refresh();

                exceptionally(static fn() => file_put_contents($file, 'first'));
                delay(ms(225));
                Assert::null($reload->tryReceive());

                exceptionally(static fn() => file_put_contents($file, 'last'));
                delay(ms(50));
                Assert::null($reload->tryReceive());

                delay(ms(50));
                Assert::same($reload->tryReceive(), true);

                delay(ms(400));
                Assert::null($reload->tryReceive());
            }));
        } finally {
            @unlink($file);
        }
    }

    public function zeroDebounceRequestsReloadOnTheFirstChangedPoll(): void
    {
        $file = exceptionally(static fn() => tempnam(sys_get_temp_dir(), 'thesis-hot-reload-'));

        try {
            virtualTime(static fn() => group(static function (Group $group) use ($file) {
                /** @var Channel<true> $reload */
                $reload = new Channel(capacity: 1);
                $watcher = new FileWatcher([$file], ZERO, $reload);
                $watcher->run($group);
                $watcher->refresh();

                exceptionally(static fn() => file_put_contents($file, 'edit'));
                delay(ms(199));
                Assert::null($reload->tryReceive());

                delay(ms(2));
                Assert::same($reload->tryReceive(), true);
            }));
        } finally {
            @unlink($file);
        }
    }
}
