<?php

declare(strict_types=1);

namespace Thesis\Formatter\Internal;

use Thesis\SourceLocation\Location;
use function Thesis\Formatter\formatValue;

/**
 * An anonymous class is named "<parent>@anonymous\0<file>:<line>$<n>" and is formatted by its name, not reflected:
 * the name may come from another process, where the class is not declared.
 *
 * @internal
 * @return non-empty-string
 */
function className(string $class): string
{
    if (preg_match('/^(.+)@anonymous\x00([^\x00-\x1F\x7F]+):([1-9]\d*)\$[0-9a-f]+$/D', $class, $matches) === 1) {
        /** @var positive-int $line */
        $line = (int) $matches[3];

        return name($matches[1]) . '@' . Location::of($matches[2], $line);
    }

    return name($class);
}

/**
 * A name of a class, a function, a method or a property as it is, anything else as a string literal.
 *
 * @internal
 * @return non-empty-string
 */
function name(string $name): string
{
    $label = '[a-zA-Z_\x80-\xFF][a-zA-Z0-9_\x80-\xFF]*';

    if ($name !== '' && preg_match("/^\\\\?{$label}(\\\\{$label})*$/D", $name) === 1) {
        return $name;
    }

    return formatValue($name);
}

/**
 * The function describes a callable or an invalid callable without using the value formatter.
 *
 * @internal
 * @return non-empty-string
 */
function functionName(mixed $fn): string
{
    if (\is_string($fn)) {
        $offset = strpos($fn, '::');

        if ($offset === false) {
            return name($fn);
        }

        return className(substr($fn, 0, $offset)) . '::' . name(substr($fn, $offset + 2));
    }

    if (\is_array($fn) && array_is_list($fn) && \count($fn) === 2 && \is_string($fn[1])) {
        if (\is_object($fn[0])) {
            return className($fn[0]::class) . '::' . name($fn[1]);
        }

        if (\is_string($fn[0])) {
            return className($fn[0]) . '::' . name($fn[1]);
        }
    }

    if ($fn instanceof \Closure) {
        return reflectedFunctionName(new \ReflectionFunction($fn));
    }

    if (\is_object($fn)) {
        return className($fn::class);
    }

    return formatValue($fn);
}

/**
 * @internal
 * @return non-empty-string
 */
function reflectedFunctionName(\ReflectionFunctionAbstract $function): string
{
    // First: the parameter of a closure declared in a method gives a \ReflectionMethod named {closure:...}.
    if (str_starts_with($function->name, '{closure')) {
        $location = Location::ofFunction($function);
        \assert($location !== null, 'Only user code declares closures.');

        return 'function@' . $location;
    }

    if ($function instanceof \ReflectionMethod) {
        return className($function->class) . '::' . $function->name;
    }

    // A first-class callable of a method, Foo::bar(...), is a \ReflectionFunction named bar.
    $class = $function->getClosureCalledClass();

    if ($class === null) {
        /** @var non-empty-string */
        return $function->name;
    }

    return className($class->name) . '::' . $function->name;
}
