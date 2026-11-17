<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use Thesis\Async\Channel;
use Thesis\Async\Group;
use function Thesis\Async\group;

/**
 * The cost of a switch between two fibers: every round trip is two waits and two resumes.
 */
#[Warmup(1)]
final class ChannelBench
{
    #[Expected(instructions: '52.03M', cycles: '80.91M', memory: '30.20KiB')]
    public function benchPingPong(): void
    {
        $pings = new Channel(capacity: 0);
        $pongs = new Channel(capacity: 0);

        group(static function (Group $group) use ($pings, $pongs) {
            $group->spawn(static function () use ($pings, $pongs) {
                foreach ($pings as $ping) {
                    $pongs->send($ping);
                }
            });

            for ($i = 0; $i < 1000; ++$i) {
                $pings->send($i);
                $pongs->receive();
            }

            $pings->close();
        });
    }
}
