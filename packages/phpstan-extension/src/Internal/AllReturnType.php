<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * all() of an array shape of callables returns the shape of their results, where the generics of PHPDoc
 * give one value type for every key. The extension preserves list and non-empty array types.
 *
 * @internal
 */
final readonly class AllReturnType implements DynamicFunctionReturnTypeExtension
{
    #[\Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'Thesis\Async\all';
    }

    #[\Override]
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        $tasks = Argument::find($functionCall, name: 'tasks', position: 0);

        if ($tasks === null) {
            return null;
        }

        $tasksType = $scope->getType($tasks->value);
        $shapes = $tasksType->getConstantArrays();

        if ($shapes === []) {
            if (!$tasksType->isArray()->yes()) {
                return null;
            }

            $default = ParametersAcceptorSelector::selectFromArgs(
                scope: $scope,
                args: $functionCall->getArgs(),
                parametersAcceptors: $functionReflection->getVariants(),
            )->getReturnType();

            return Arrays::withListAndNonEmptyOf($tasksType, $default);
        }

        $types = [];

        foreach ($shapes as $shape) {
            $type = self::resultsOf($shape, $scope);

            if ($type === null) {
                return null;
            }

            $types[] = $type;
        }

        return TypeCombinator::union(...$types);
    }

    private static function resultsOf(ConstantArrayType $tasks, Scope $scope): ?Type
    {
        $results = ConstantArrayTypeBuilder::createEmpty();

        foreach ($tasks->getKeyTypes() as $i => $key) {
            $result = Tasks::resultOf($tasks->getOffsetValueType($key), $scope);

            if ($result === null) {
                return null;
            }

            $results->setOffsetValueType($key, $result, optional: $tasks->isOptionalKey($i));
        }

        return $results->getArray();
    }
}
