# Thesis Source Location

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/source-location/php)](https://packagist.org/packages/thesis/source-location)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/source-location)](https://github.com/thesis-php/source-location/releases)

Where in the source code: a file and a line, the caller outside a library, the code of `eval()` resolved.

```php
use function Thesis\SourceLocation\caller;

final class Container
{
    public function register(string $id): void
    {
        // The line of the application that called register(), not of the library.
        $this->declaredAt[$id] = caller(skip: [__DIR__])?->location;
    }
}
```

## Installation

```shell
composer require thesis/source-location
```

Requires PHP 8.6+. No extensions.

## Location

`Location` is a file and a line. `Location::of()` resolves the code of `eval()` to the line of the outermost `eval()`,
`Location::ofFunction()` is where a function is declared (`null` for an internal one).

```php
$location = Location::of("/app/src/foo.php(7) : eval()'d code", 3);
(string) $location;   // /app/src/foo.php:7
$location->shortFile; // src/foo.php: the path from the root of the Composer project, if the file is under it

Location::ofFunction(new \ReflectionFunction(static fn() => null)); // the file and the line of the closure
```

`(string) $location` has the full path: it is exact, and a terminal or an IDE opens it. `shortFile` is for reading.

## Caller

`caller(array $skip, int $limit = 20): ?Caller` finds the first call of the stack, from the innermost, written in a file
outside the directories of `$skip`: the call of a library from the code that uses it. It gives where the call is written
and what it calls:

```php
$caller = caller(skip: [__DIR__]);
$caller->location; // /app/src/Handler.php:42
$caller->callee;   // App\Library\Container::register
(string) $caller;  // App\Library\Container::register() at /app/src/Handler.php:42
```

A closure is named as PHP names it (`{closure:App\Client::send():12}`), an anonymous class without where
it is declared (`class@anonymous::send`, `{closure:class@anonymous::send():12}`): the full name of the class
has a NUL byte, which ends the string in a log.

It returns `null` if no call within `$limit` frames is outside `$skip`: a call made by the engine itself
(a callback of the event loop) is written nowhere and is passed over. Skip the directory of the event loop too
when the library runs its own code in its callbacks.
