<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * @internal
 */
final readonly class Arrays
{
    /**
     * For a function that returns a value for every key of $input, in its order: a list gives a list,
     * a non-empty array gives a non-empty one. Null if $input is neither, $result is all there is to know.
     */
    public static function withListAndNonEmptyOf(Type $input, Type $result): ?Type
    {
        $accessories = [];

        if ($input->isList()->yes()) {
            $accessories[] = new AccessoryArrayListType();
        }

        if ($input->isIterableAtLeastOnce()->yes()) {
            $accessories[] = new NonEmptyArrayType();
        }

        return $accessories === [] ? null : TypeCombinator::intersect($result, ...$accessories);
    }
}
