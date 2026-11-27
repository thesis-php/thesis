<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * @internal
 */
final readonly class ZeroDurationType implements ExpressionTypeResolverExtension
{
    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

    #[\Override]
    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if (
            !$expr instanceof ConstFetch
            || $this->reflection->resolveConstantName($expr->name, $scope) !== 'Thesis\Time\ZERO'
        ) {
            return null;
        }

        return new ObjectType('Time\Duration');
    }
}
