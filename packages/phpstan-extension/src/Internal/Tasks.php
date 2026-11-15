<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * @internal
 */
final readonly class Tasks
{
    /**
     * What calling $task returns. Null for what is not surely a callable: the call is wrong,
     * and the type of the PHPDoc keeps its error.
     */
    public static function resultOf(Type $task, Scope $scope): ?Type
    {
        if (!$task->isCallable()->yes()) {
            return null;
        }

        return TypeCombinator::union(...array_map(
            static fn(ParametersAcceptor $acceptor) => $acceptor->getReturnType(),
            $task->getCallableParametersAcceptors($scope),
        ));
    }
}
