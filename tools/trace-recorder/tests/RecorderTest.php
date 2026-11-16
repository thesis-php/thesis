<?php

declare(strict_types=1);

namespace Thesis\TraceRecorder\Tests;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\TraceRecorder\Recorder;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\race;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class RecorderTest
{
    public function recordsRowsAndEventsOfDeadline(): void
    {
        $trace = Recorder::record(static fn() => timeoutOr(sec(2), static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(ms(500));

                return 'a';
            }, name: 'fetch A');
            $group->spawn(static fn() => delay(sec(3)), name: 'fetch B');
        })));

        Assert::same($trace['rows'], [
            ['label' => 'timeout 2s', 'scope' => 's1', 'parent' => null, 'start' => 0, 'end' => 2000, 'cancelAt' => 2000, 'outcome' => 'cancelled'],
            ['label' => 'group', 'scope' => 's2', 'parent' => 's1', 'start' => 0, 'end' => 2000, 'cancelAt' => 2000, 'outcome' => 'cancelled'],
            ['label' => 'fetch A', 'scope' => 's3', 'parent' => 's2', 'start' => 0, 'end' => 500, 'cancelAt' => null, 'outcome' => 'value'],
            ['label' => 'fetch B', 'scope' => 's4', 'parent' => 's2', 'start' => 0, 'end' => 2000, 'cancelAt' => 2000, 'outcome' => 'cancelled'],
        ]);
        Assert::same(array_map(static fn(array $event) => $event['t'] . ' ' . $event['kind'] . ' ' . $event['text'], $trace['events']), [
            '0 create timeout 2s is created',
            '0 create group is created in timeout 2s',
            '0 create fetch A is created in group',
            '0 create fetch B is created in group',
            '0 join group waits for its tasks',
            '0 start fetch A starts',
            '0 suspend fetch A waits in delay()',
            '0 start fetch B starts',
            '0 suspend fetch B waits in delay()',
            '500 resume fetch A wakes up',
            "500 end fetch A returns 'a'",
            '2000 cancel the deadline of timeout 2s passes, reaching timeout 2s, group, fetch B',
            '2000 resume fetch B wakes up cancelled',
            '2000 end fetch B ends cancelled',
            '2000 end group ends cancelled',
            '2000 end timeout 2s ends cancelled',
        ]);
    }

    public function labelsGroupsByTheirFunction(): void
    {
        $trace = Recorder::record(static fn() => race(['eu' => static fn() => delay(sec(2)), 'us' => static fn() => 'us']));

        Assert::same(array_column($trace['rows'], 'label'), ['race', 'eu', 'us']);
    }

    public function labelsNamedGroupByItsName(): void
    {
        $trace = Recorder::record(static fn() => group(static fn() => null, name: 'checkout'));

        Assert::same(array_column($trace['rows'], 'label'), ['checkout']);
    }

    public function namesUnlabelledTasksByScope(): void
    {
        $trace = Recorder::record(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => null);
            $group->daemon(static fn() => null);
        }));

        Assert::same(array_column($trace['rows'], 'label'), ['group', 'task #2', 'daemon #3']);
    }
}
