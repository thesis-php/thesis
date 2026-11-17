# Thesis Formatter

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/formatter/php)](https://packagist.org/packages/thesis/formatter)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/formatter)](https://github.com/thesis-php/formatter/releases)

Formats PHP values, classes, functions and parameters into short strings for error messages.

```php
use function Thesis\Formatter\formatValue;
use function Thesis\Formatter\formatParameter;

throw new \ValueError(\sprintf('%s must be positive, got %s.', formatParameter($fn, 'limit'), formatValue($limit)));
// Thesis\Example\Client::fetch($limit) must be positive, got -1.
```

## Installation

```shell
composer require thesis/formatter
```

Requires 64-bit PHP 8.6+. No extensions.

## Values

`formatValue(mixed $value): string` writes a value in the notation of PHPStan types, so that it reads like a type:

```php
formatValue(null);                    // null
formatValue(42);                      // 42
formatValue(1.0);                     // 1.0, apart from an int
formatValue("it's");                  // 'it\'s'
formatValue("line\n");                // "line\n"
formatValue("Приве\xD1");             // "Приве\xD1"
formatValue("abc\u{202E}");           // "abc\u{202E}"
formatValue([]);                      // array{}
formatValue([1, 2]);                  // list{1, 2}
formatValue(['id' => 1, 'a b' => 2]); // array{id: 1, 'a b': 2}
formatValue((object) ['x' => 1]);     // object{x: 1}
formatValue(Suit::Hearts);            // App\Suit::Hearts
formatValue(new Service());           // App\Service
formatValue(new class {});            // class@/app/src/foo.php:12
formatValue(static fn() => null);     // function@/app/src/foo.php:12()
formatValue($stream);                 // resource (stream)
```

A string is a PHP literal of the same bytes. It is single-quoted while every character shows as itself,
otherwise double-quoted, with an escape for each byte that is not part of a valid UTF-8 character and for each
character that does not show or does not show as itself: control (`\n`, `\x00`, `\u{9B}`), format (bidi controls,
zero-width characters, `\u{FEFF}`) and space other than U+0020 (`\u{A0}`). UTF-8 around them stays as is.

The result stays short whatever the value is, so it is safe for a value that came from outside:

- the items of arrays and objects stop once the result reaches 500 bytes: `list{1, 2, 3, ...}`,
- a string shows at most 100 characters and only what is left of the 500 bytes, followed by `...` if cut:
  `'aaa…'...`,
- an array or an object nested deeper than 3 levels is written as `list{...}`, which also ends a cycle.

So the result goes past 500 bytes only by the punctuation around the last item (about 15 bytes) and by a name
of a class or a closure.

A float is written by `var_export()`, with the digits that the `serialize_precision` ini setting gives
(all that make it exact by default). An object other than an enum or a closure is its class:
only `\stdClass` itself shows its properties, not a subclass of it.

## Code

```php
formatClass(Service::class);                 // App\Service
formatClass(new class extends Base {});      // App\Base@/app/src/foo.php:12
formatFunction('strlen');                    // strlen()
formatFunction([Service::class, 'create']);  // App\Service::create()
formatFunction($service->handle(...));       // App\Service::handle()
formatFunction(new Invokable());             // App\Invokable()
formatFunction(static fn() => null);         // function@/app/src/foo.php:12()
formatParameter([$service, 'handle'], 'id'); // App\Service::handle($id)
formatProperty(Service::class, 'count');     // App\Service::$count
```

An anonymous class and a closure are named by the file and the line where they are declared (the line of `eval()`
for code that it runs). Nothing is loaded or reflected to format a name: an error may be about a class or a function
that does not exist, or a name of an anonymous class from another process. A string that is not a name
of PHP is formatted as a string literal (`formatClass("a\nb")` is `"a\nb"`), and what is not a callable
as a value (`formatFunction(42)` is `42()`), so the functions never fail.

The same for reflection: `formatReflectedClass()`, `formatReflectedFunction()` (a function, a method or a closure),
`formatReflectedParameter()` and `formatReflectedProperty()`. A type is formatted by PHP: `(string) $type`.
