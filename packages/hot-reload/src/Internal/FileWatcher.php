<?php

declare(strict_types=1);

namespace Thesis\HotReload\Internal;

use Thesis\Async\Channel;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Time\Duration;
use function Thesis\Async\delay;
use function Thesis\Async\isCancelled;
use function Thesis\exceptionally;
use function Thesis\Time\ms;

/**
 * @internal
 */
final class FileWatcher implements Watcher
{
    /**
     * @var array<array-key, string>
     */
    private array $snapshot = [];

    private ?Instant $changedAt = null;

    /**
     * @param list<string> $paths
     * @param Channel<true> $reload
     */
    public function __construct(
        private readonly array $paths,
        private readonly Duration $debounce,
        private readonly Channel $reload,
    ) {
        if ($debounce->negative) {
            throw new \InvalidArgumentException('The debounce duration must be non-negative.');
        }
    }

    public function run(Group $group): void
    {
        $group->daemon(function () {
            $interval = ms(200);

            while (!isCancelled()) {
                $remaining = $this->remainingDebounce();
                delay($remaining === null ? $interval : min($remaining, $interval));

                if ($this->poll()) {
                    $this->reload->trySend(true);
                }
            }
        });
    }

    /**
     * Takes the snapshot for a launch and clears any edits already covered by that launch.
     * Scanning does not suspend.
     */
    public function refresh(): void
    {
        $this->snapshot = $this->scan();
        $this->changedAt = null;
    }

    private function remainingDebounce(): ?Duration
    {
        return $this->changedAt === null ? null : $this->debounce->sub(Instant::now()->since($this->changedAt));
    }

    private function poll(): bool
    {
        $current = $this->scan();
        if ($current !== $this->snapshot) {
            $this->snapshot = $current;
            $this->changedAt = Instant::now();
        }

        $remaining = $this->remainingDebounce();
        if ($remaining === null || $remaining > ms(0)) {
            return false;
        }

        $this->changedAt = null;

        return true;
    }

    /**
     * @return array<array-key, string>
     */
    private function scan(): array
    {
        clearstatcache(true);
        $snapshot = [];

        foreach ($this->paths as $path) {
            foreach (self::files($path) as $file) {
                try {
                    $snapshot[$file] ??= exceptionally(static fn() => hash_file('xxh128', $file));
                } catch (\ErrorException $error) {
                    clearstatcache(true, $file);
                    if (is_file($file)) {
                        throw $error;
                    }
                }
            }
        }

        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    /**
     * @return \Generator<string>
     */
    private static function files(string $path): \Generator
    {
        if (is_file($path)) {
            yield $path;

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        try {
            $entries = exceptionally(static fn() => scandir($path, SCANDIR_SORT_NONE));
        } catch (\ErrorException $error) {
            clearstatcache(true, $path);
            if (is_dir($path)) {
                throw $error;
            }

            return;
        }

        foreach ($entries as $entry) {
            $child = $path . '/' . $entry;
            if ($entry !== '.' && $entry !== '..' && !is_link($child)) {
                yield from self::files($child);
            }
        }
    }
}
