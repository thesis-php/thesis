<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * allSettled() of an array shape of callables returns the shapes of their results and failures.
 * Every key is optional in both: which of them a key goes to is known only at runtime. For the same reason
 * nothing is said of an array that is not a shape: a failure leaves a hole in a list, either array may be empty.
 *
 * @internal
 */
final readonly class AllSettledReturnType implements DynamicFunctionReturnTypeExtension
{
    #[\Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'Thesis\Async\allSettled';
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

        $shapes = $scope->getType($tasks->value)->getConstantArrays();

        if ($shapes === []) {
            return null;
        }

        $types = [];

        foreach ($shapes as $shape) {
            $type = self::settledOf($shape, $scope);

            if ($type === null) {
                return null;
            }

            $types[] = $type;
        }

        return TypeCombinator::union(...$types);
    }

    private static function settledOf(ConstantArrayType $tasks, Scope $scope): ?Type
    {
        $results = ConstantArrayTypeBuilder::createEmpty();
        $errors = ConstantArrayTypeBuilder::createEmpty();
        $error = new ObjectType(\Throwable::class);

        foreach ($tasks->getKeyTypes() as $key) {
            $result = Tasks::resultOf($tasks->getOffsetValueType($key), $scope);

            if ($result === null) {
                return null;
            }

            $results->setOffsetValueType($key, $result, optional: true);
            $errors->setOffsetValueType($key, $error, optional: true);
        }

        $settled = ConstantArrayTypeBuilder::createEmpty();
        $settled->setOffsetValueType(null, $results->getArray());
        $settled->setOffsetValueType(null, $errors->getArray());

        return $settled->getArray();
    }
}
