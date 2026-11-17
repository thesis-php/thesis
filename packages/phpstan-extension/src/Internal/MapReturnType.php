<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * map() of an array keeps what is known of its keys: an array shape gives the same shape of results,
 * a list a list, a non-empty array a non-empty one. The value type is the one of the PHPDoc for every key:
 * $fn is one callable for all items.
 *
 * @internal
 */
final readonly class MapReturnType implements DynamicFunctionReturnTypeExtension
{
    #[\Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'Thesis\Async\map';
    }

    #[\Override]
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        $items = Argument::find($functionCall, name: 'items', position: 0);

        if ($items === null) {
            return null;
        }

        $itemsType = $scope->getType($items->value);
        $default = ParametersAcceptorSelector::selectFromArgs(
            scope: $scope,
            args: $functionCall->getArgs(),
            parametersAcceptors: $functionReflection->getVariants(),
        )->getReturnType();
        // A generator may repeat a key or end early: it is no shape, no list and not surely non-empty,
        // so the type of an iterable that is not an array stays the one of the PHPDoc.
        $shapes = $itemsType->getConstantArrays();

        if ($shapes === []) {
            return Arrays::withListAndNonEmptyOf($itemsType, $default);
        }

        $result = $default->getIterableValueType();
        $types = [];

        foreach ($shapes as $shape) {
            $results = ConstantArrayTypeBuilder::createEmpty();

            foreach ($shape->getKeyTypes() as $i => $key) {
                $results->setOffsetValueType($key, $result, optional: $shape->isOptionalKey($i));
            }

            $types[] = $results->getArray();
        }

        return TypeCombinator::union(...$types);
    }
}
