<?php

declare(strict_types=1);

namespace Thesis\Formatter;

use Thesis\Formatter\Internal\ValueFormatter;

/**
 * @api
 * @return non-empty-string
 */
function format(mixed $value): string
{
    return ValueFormatter::format($value);
}

/**
 * @api
 * @param string|object $class a string that is not a name of a class is formatted as a string literal
 * @return non-empty-string
 */
function formatClass(string|object $class): string
{
    return Internal\className(\is_object($class) ? $class::class : $class);
}

/**
 * @api
 * @param callable|string|array{object|string, string} $fn not a native type: checking a callable would autoload
 *     the class of 'Foo::bar', and the function may be one that does not exist
 * @return non-empty-string
 * @phpstan-ignore missingType.callable (any callable is formatted)
 */
function formatFunction(mixed $fn): string
{
    return Internal\functionName($fn) . '()';
}

/**
 * @api
 * @param callable|string|array{object|string, string} $fn not a native type: checking a callable would autoload
 *     the class of 'Foo::bar', and the function may be one that does not exist
 * @return non-empty-string
 * @phpstan-ignore missingType.callable (any callable is formatted)
 */
function formatParameter(mixed $fn, string $parameter): string
{
    return \sprintf('%s($%s)', Internal\functionName($fn), Internal\name($parameter));
}

/**
 * @api
 * @return non-empty-string
 */
function formatProperty(string|object $class, string $property): string
{
    return \sprintf('%s::$%s', formatClass($class), Internal\name($property));
}

/**
 * @api
 * @param \ReflectionClass<*> $class
 * @return non-empty-string
 */
function formatReflectedClass(\ReflectionClass $class): string
{
    return Internal\className($class->name);
}

/**
 * @api
 * @return non-empty-string
 */
function formatReflectedFunction(\ReflectionFunctionAbstract $function): string
{
    return Internal\reflectedFunctionName($function) . '()';
}

/**
 * @api
 * @return non-empty-string
 */
function formatReflectedParameter(\ReflectionParameter $parameter): string
{
    return \sprintf(
        '%s($%s)',
        Internal\reflectedFunctionName($parameter->getDeclaringFunction()),
        $parameter->name,
    );
}

/**
 * @api
 * @return non-empty-string
 */
function formatReflectedProperty(\ReflectionProperty $property): string
{
    return \sprintf('%s::$%s', Internal\className($property->class), Internal\name($property->name));
}
