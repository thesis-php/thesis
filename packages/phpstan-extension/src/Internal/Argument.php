<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;

/**
 * @internal
 */
final readonly class Argument
{
    /**
     * Null for a call with an unpacked argument: which of them is the parameter is not known.
     *
     * @param non-empty-string $name
     * @param non-negative-int $position
     */
    public static function find(FuncCall $call, string $name, int $position): ?Arg
    {
        foreach ($call->getArgs() as $index => $argument) {
            if ($argument->unpack) {
                return null;
            }

            $found = $argument->name === null ? $index === $position : $argument->name->toString() === $name;

            if ($found) {
                return $argument;
            }
        }

        return null;
    }
}
