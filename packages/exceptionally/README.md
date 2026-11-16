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
exceptionally($fn, E_USER_WARNING | E_USER_NOTICE);
exceptionally($fn, E_ALL); // deprecations too
```

An error at another level goes to the error handler that was set before, as without `exceptionally()`.
In nested calls the innermost one decides.

## Suppressed errors

An error suppressed with `@` is not thrown, it goes to the previous handler:

```php
$content = exceptionally(static fn() => @file_get_contents('/path/to/file')); // false, no exception
```

The same goes for `@` around the call itself and for levels turned off by the `error_reporting` ini setting:
a result like `false` is then not a proof of success. As in PHP, `@` does not suppress `E_USER_ERROR`
and `E_RECOVERABLE_ERROR`, they are thrown.

## Fibers

The function may suspend the fiber, for example to wait for I/O. While it is suspended, errors of other fibers
are not thrown: they go to the previous handler. A fiber started inside the function is another fiber too,
its errors are not thrown either.

## Other error handlers

The error handlers of PHP form one stack for all fibers. `exceptionally()` sets its handler on top
and takes off only its own handlers, and only from the top. So:

- A handler set while the function runs, by the function itself or by another fiber while it is suspended,
  stays. It is above the handler of `exceptionally()` and gets the errors first: they are thrown only if it passes
  them on to the handler `set_error_handler()` returned. Set the handler of the application before such calls start.
- A handler set before a call must not be restored while the call runs. `restore_error_handler()` then takes off
  the handler of `exceptionally()`: the errors of the call are not thrown anymore, and the handler that was meant
  stays set.
- The previous handler must not suspend the fiber: while a handler runs, PHP has no handler, and if another fiber
  sets one meanwhile, PHP forgets the one that was running. This breaks the handlers with or without
  `exceptionally()`.
- `exit()` and a fatal error do not finish the call: the shutdown functions run as if inside it,
  their errors at its levels are thrown.
