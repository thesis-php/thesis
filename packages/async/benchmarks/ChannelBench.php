<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use Thesis\Async\Channel;
use Thesis\Async\Group;
use function Thesis\Async\group;

#[Warmup(1)]
final class ChannelBench
{
    #[Expected(instructions: '2.527M', cycles: '2.527M', memory: '352B')]
    public function benchCreateRendezvous(): void
    {
        for ($i = 0; $i < 1000; ++$i) {
            /** @phpstan-ignore new.resultUnused (the benchmark measures construction) */
            new Channel(0);
        }
    }

    #[Expected(instructions: '2.781M', cycles: '2.784M', memory: '688B')]
    public function benchBufferedRoundTrip(): void
    {
        $channel = new Channel(1);

        for ($i = 0; $i < 1000; ++$i) {
            $channel->send($i);
            $channel->receive();
        }
    }

    /**
     * Every round trip is two waits and two resumes.
     */
    #[Expected(instructions: '37.85M', cycles: '57.97M', memory: '33.04KiB')]
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
