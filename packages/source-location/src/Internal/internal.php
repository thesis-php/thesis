<?php

declare(strict_types=1);

namespace Thesis\SourceLocation\Internal;

/**
 * A closure is named as PHP names it, with the class it is declared in. An anonymous class loses
 * "\0<file>:<line>$<n>" wherever it is in the name, in a closure declared in its method too: a NUL byte ends
 * the string in a log, and where the class is declared is not where it is called.
 *
 * @internal
 * @param non-empty-string $function
 * @param ?non-empty-string $class
 * @return non-empty-string
 */
function callee(string $function, ?string $class): string
{
    if (!str_starts_with($function, '{closure')) {
        return $class === null ? $function : beforeNul($class) . '::' . $function;
    }

    // Greedy: the path may hold ":<line>$<hex>" itself, and what follows the class (`::make():15}`) never does.
    $name = preg_replace('/@anonymous\x00.*:\d+\$[0-9a-f]+/s', '@anonymous', $function);

    // A NUL must not get through even if PCRE gives up (the backtrack limit): the rest of the name is lost then.
    return beforeNul($name ?? $function);
}

/**
 * @internal
 * @param non-empty-string $name
 * @return non-empty-string
 */
function beforeNul(string $name): string
{
    $nul = strpos($name, "\0");

    /** @var non-empty-string PHP names nothing with a NUL at the start */
    return $nul === false ? $name : substr($name, 0, $nul);
}
