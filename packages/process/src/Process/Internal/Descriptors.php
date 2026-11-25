<?php

declare(strict_types=1);

namespace Thesis\Process\Internal;

use function Thesis\exceptionally;

/**
 * @internal
 */
final class Descriptors
{
    /**
     * @param array<array-key, mixed> $descriptors
     * @return array<non-negative-int, resource|array{'null'}>
     */
    public static function prepare(array $descriptors): array
    {
        $spec = [];
        foreach ($descriptors as $fd => $stream) {
            if (!\is_int($fd) || $fd < 0 || $fd > 0x7F_FF_FF_FF) {
                throw new \ValueError('A descriptor number must fit in a non-negative C int.');
            }

            if (!\is_resource($stream) || get_resource_type($stream) !== 'stream') {
                throw new \ValueError("Descriptor {$fd} must be an open stream.");
            }

            // A user wrapper may run PHP during proc_open(). php://temp opens a backing file on its first cast.
            // Either would change the descriptor table after we scanned it.
            $type = stream_get_meta_data($stream)['stream_type'];
            if ($type !== 'STDIO' && !str_contains($type, '_socket')) {
                throw new \ValueError("Descriptor {$fd} must be a native file, pipe or socket.");
            }

            $spec[$fd] = $stream;
        }

        $directory = is_dir('/dev/fd') ? '/dev/fd' : '/proc/self/fd';
        $entries = exceptionally(static fn() => scandir($directory, SCANDIR_SORT_NONE));
        $open = [];
        clearstatcache();

        foreach ($entries as $entry) {
            $fd = (int) $entry;

            // scandir() has already closed the descriptor it opened to read the directory.
            if ((string) $fd !== $entry || $fd < 0 || @lstat("{$directory}/{$entry}") === false) {
                continue;
            }

            $open[$fd] = true;

            if ($fd > 2) {
                $spec[$fd] ??= ['null'];
            }
        }

        // proc_open() takes one temporary descriptor per stream or null mapping, in array order.
        // A dup2() to a later temporary would overwrite its source. Assign every target that is also
        // a temporary to its own slot, then fill the other slots with the remaining targets.
        $slots = [];
        $remaining = $spec;
        $fd = 0;
        $count = \count($spec);

        for ($i = 0; $i < $count; ++$i, ++$fd) {
            while (isset($open[$fd])) {
                ++$fd;
            }

            if (isset($remaining[$fd])) {
                $slots[$i] = $fd;
                unset($remaining[$fd]);
            }
        }

        $other = array_keys($remaining);
        $next = 0;
        $ordered = [];

        for ($i = 0; $i < $count; ++$i) {
            $target = $slots[$i] ?? $other[$next++] ?? null;
            \assert($target !== null && isset($spec[$target]));
            $ordered[$target] = $spec[$target];
        }

        return $ordered;
    }
}
