# Thesis Exceptionally

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/exceptionally/php)](https://packagist.org/packages/thesis/exceptionally)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/exceptionally)](https://github.com/thesis-php/exceptionally/releases)

Turns PHP errors into exceptions around a callable. Safe when the callable suspends a fiber.

```php
use function Thesis\exceptionally;

// throws \ErrorException instead of a warning and false
$content = exceptionally(static fn() => file_get_contents('/path/to/file'));
```

## Installation

```shell
composer require thesis/exceptionally
```

Requires 64-bit PHP 8.5+. No extensions.

## Why

Many functions of PHP report a failure with a warning and `false`. `exceptionally()` calls a function and throws
an error it triggers as an [`\ErrorException`](https://php.net/manual/class.errorexception.php),
with the message, the level (`getSeverity()`), the file and the line of the error.

## Levels

By default every level except `E_DEPRECATED` and `E_USER_DEPRECATED` is thrown: deprecations usually come
from other libraries and should not stop the code. The second argument sets the levels:

```php
exceptionally($function, E_USER_WARNING | E_USER_NOTICE);
exceptionally($function, E_ALL); // deprecations too
```

An error at another level goes to the error handler that was set before, as without `exceptionally()`.
In nested calls the innermost one decides.

## Suppressed errors

An error suppressed with `@` is not thrown, it goes to the previous handler:

```php
$content = exceptionally(static fn() => @file_get_contents('/path/to/file')); // false, no exception
```

## Fibers

The function may suspend the fiber, for example to wait for I/O. While it is suspended, errors of other fibers
are not thrown: they go to the previous handler. A fiber started inside the function is another fiber too,
its errors are not thrown either.
