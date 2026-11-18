<?php

declare(strict_types=1);

namespace Thesis\Async;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Test;
use Thesis\Async\Internal\Loop;
use Thesis\Dispatcher;
use function Thesis\Async\Tests\later;
use function Thesis\Async\Tests\secondsSince;
use function Thesis\Async\Trace\observe;
use function Thesis\Time\sec;

/**
 * Random programs of pipes on virtual time, each seed in a virtualTime() of its own: whether the clock may jump
 * depends on what else is pending and ready, and hand-written tests cover one shape at a time.
 * A seed draws everything up front and shuffles the fibers ready in one tick, so it replays the run; a broken one
 * is listed with it.
 */
#[Test]
final class RandomizedVirtualTimeTest
{
    private Randomizer $random;

    private int $seed = 0;

    /**
     * @var list<string>
     */
    private array $violations = [];

    public function __construct()
    {
        $this->random = new Randomizer();
    }

    public function readerOfPipeWakesAtTimeOfWrite(): void
    {
        $this->seeds(count: 100, program: $this->pipes(...));
    }

    /**
     * An observer only observes: the same programs, traced, behave the same, and every trace is whole.
     */
    public function traceChangesNothing(): void
    {
        $checker = new TraceChecker();
        observe(new Dispatcher([$checker]), fn() => $this->seeds(count: 100, program: $this->pipes(...)));

        Assert::true($checker->events > 0);
        Assert::same($checker->violations, []);
        Assert::same($checker->unfinished(), []);
    }

    /**
     * @param positive-int $count
     * @param callable(): void $program
     */
    private function seeds(int $count, callable $program): void
    {
        for ($this->seed = 1; $this->seed <= $count; ++$this->seed) {
            $this->random = new Randomizer(new Mt19937($this->seed));
            // The seed draws the program and shuffles what is ready in one tick: it replays both.
            virtualTime(function () use ($program) {
                $program();

                if (!Loop::get()->isIdle) {
                    $this->violate('callbacks are left');
                }
            }, $this->seed);
        }

        Assert::same($this->violations, []);
    }

    private function violate(string $violation): void
    {
        $this->violations[] = \sprintf('seed %d: %s', $this->seed, $violation);
    }

    /**
     * @param positive-int $outOf
     */
    private function chance(int $outOf): bool
    {
        return $this->random->getInt(1, $outOf) === 1;
    }

    /**
     * Readers, writers in tasks or in raw unreferenced timers, readers that relay to the next pipe,
     * and a daemon with a timer of its own, all under a deadline that never comes.
     */
    private function pipes(): void
    {
        $start = Instant::now();
        /** @var list<array{resource, resource}> $pairs */
        $pairs = [];

        for ($i = $this->random->getInt(1, 6); $i > 0; --$i) {
            /** @var array{resource, resource} $pair */
            $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
            stream_set_blocking($pair[0], false);
            $pairs[] = $pair;
        }

        $plans = [];
        $relayedInto = false;

        foreach ($pairs as $pipe => [$reader, $writer]) {
            $next = $pairs[$pipe + 1] ?? null;
            $relays = $next !== null && $this->chance(2);
            $plans[] = [
                'pipe' => $pipe,
                'reader' => $reader,
                'writer' => $writer,
                'readerDelay' => $this->random->getInt(0, 4),
                // A relayed pipe is written by its writer too, later, in case the relay comes after it.
                'writerDelay' => $this->random->getInt(0, 4) + ($relayedInto ? 5 : 0),
                'checkpoints' => $this->random->getInt(0, 2),
                'relayTo' => $relays ? [$pipe + 1, $next[1]] : null,
                'raw' => $this->chance(4),
            ];
            $relayedInto = $relays;
        }

        $daemonInterval = $this->chance(3) ? $this->random->getInt(3, 9) : null;
        /** @var array<int, float> $writtenAt */
        $writtenAt = [];
        $write = static function (int $pipe, mixed $writer) use ($start, &$writtenAt) {
            \assert(\is_resource($writer));

            if (!isset($writtenAt[$pipe])) {
                $writtenAt[$pipe] = secondsSince($start);
                fwrite($writer, 'x');
            }
        };

        $readAll = function (Group $group) use ($plans, $daemonInterval, $start, $write, &$writtenAt) {
            if ($daemonInterval !== null) {
                $group->daemon(static function () use ($daemonInterval) {
                    while (!isCancelled()) {
                        delay(sec($daemonInterval));
                    }
                });
            }

            foreach ($plans as $plan) {
                $pipe = $plan['pipe'];
                $group->spawn(function () use ($plan, $pipe, $start, $write, &$writtenAt) {
                    delay(sec($plan['readerDelay']));

                    for ($i = 0; $i < $plan['checkpoints']; ++$i) {
                        checkpoint();
                    }

                    $began = secondsSince($start);
                    $watcher = new StreamWatcher($plan['reader']);
                    $watcher->readable();
                    $watcher->close();
                    $woke = secondsSince($start);
                    $expected = max($began, $writtenAt[$pipe] ?? INF);

                    if ($woke !== $expected) {
                        $this->violate(\sprintf('pipe %d woke at %s s, expected %s s', $pipe, $woke, $expected));
                    }

                    if ($plan['relayTo'] !== null) {
                        $write(...$plan['relayTo']);
                    }
                });

                $writer = $plan['writer'];
                $writerDelay = $plan['writerDelay'];

                if ($plan['raw']) {
                    later(sec($writerDelay), static function () use ($write, $pipe, $writer) {
                        $write($pipe, $writer);
                    }, referenced: false);
                    // The raw timer does not keep the loop alive: this task does, until it fires.
                    $group->spawn(static fn() => delay(sec($writerDelay)));
                } else {
                    $group->spawn(static function () use ($write, $pipe, $writer, $writerDelay) {
                        delay(sec($writerDelay));
                        $write($pipe, $writer);
                    });
                }
            }
        };

        try {
            timeout(sec($this->random->getInt(50, 100)), static fn() => group($readAll));
        } catch (\Throwable $error) {
            $this->violate(\sprintf('%s: %s', $error::class, $error->getMessage()));
        }
    }
}
