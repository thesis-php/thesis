<?php

declare(strict_types=1);

namespace Thesis\Rector;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Reflection\ClassReflection;
use SavinMikhail\AddNamedArgumentsRector\Config\ConfigStrategy;
use SavinMikhail\AddNamedArgumentsRector\Config\DefaultStrategy;

/**
 * Names the arguments of a call with more than two of them, see "PHP code" in the root AGENTS.md.
 *
 * @internal
 */
final readonly class MoreThanTwoArgumentsStrategy implements ConfigStrategy
{
    #[\Override]
    public static function shouldApply(
        FuncCall|StaticCall|MethodCall|New_ $node,
        array $parameters,
        ?ClassReflection $classReflection = null,
    ): bool {
        return \count($node->args) > 2 && DefaultStrategy::shouldApply(
            node: $node,
            parameters: $parameters,
            classReflection: $classReflection,
        );
    }
}
