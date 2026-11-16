<?php

declare(strict_types=1);

namespace Thesis\SourceLocation;

use function Thesis\SourceLocation\Internal\callee;

/**
 * The first call of the stack, from the innermost, that is written in a file outside the directories of $skip:
 * the call of a library from the code that uses it. Null if there is none within $limit frames.
 *
 * Usually $skip is the `src` of the library that calls caller(). A call written in the code of eval()
 * is located at the line of the outermost eval().
 *
 * @api
 * @param non-empty-list<non-empty-string> $skip directories, with or without a trailing separator
 * @param positive-int $limit frames to look through, the call of caller() included
 */
function caller(array $skip, int $limit = 20): ?Caller
{
    $prefixes = [];

    foreach ($skip as $directory) {
        $prefixes[] = rtrim($directory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
    }

    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit) as $frame) {
        $file = $frame['file'] ?? '';
        $line = $frame['line'] ?? 0;

        // A call from the engine (a callback of array_map(), the start of a fiber) is written nowhere.
        if ($file === '' || $line < 1) {
            continue;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                continue 2;
            }
        }

        /** @var non-empty-string $function the engine names every function it calls */
        $function = $frame['function'];

        return new Caller(Location::of($file, $line), callee($function, $frame['class'] ?? null));
    }

    return null;
}
