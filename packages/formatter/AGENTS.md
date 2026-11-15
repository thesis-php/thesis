# thesis/formatter

Functions that format values and code for error messages. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- Formatting never fails and never grows without a bound: it runs while an error is reported, often on a value
  from outside. Every function takes any input of its native type and formats what it does not recognize
  as a value or a string literal (`formatFunction(42)` is `42()`), never asserts on it.
- `formatValue()` writes into one buffer (`ValueFormatter::$output`): items stop once it reaches `MAX_LENGTH`,
  and a string or a key gets only what is left of it, so the output goes past `MAX_LENGTH` by the punctuation
  around the last item (515 bytes in the tests) and by a name of a class or a closure, which do not come from
  outside. Nesting stops at `MAX_DEPTH`, which also ends cycles of objects and references.
- A string is a valid PHP literal of the same bytes (`FormatFuzzTest` evaluates it back) and shows nothing
  that is invisible or looks like something else: `StringFormatter` splits it into well-formed UTF-8 characters
  and single bytes, and escapes the bytes, `\p{Cc}`, `\p{Cf}` and `\p{Z}` but U+0020. Validity is judged
  per character, not for the whole string: one bad byte after the cut, or a character cut by `substr()` upstream,
  does not turn readable text into hex. Only the shown part is split, never the whole string.
- Single-quoted while nothing is escaped, and then a backslash is escaped only where PHP reads it as an escape
  (before `'`, `\` or the end): class names in strings stay readable (`'Foo\Bar'`).
- Nothing is loaded or reflected to format a name: an anonymous class is parsed from its name
  (`<parent>@anonymous\0<file>:<line>$<n>`), since the name may come from another process where it is not declared
  (reflecting it threw), and `formatFunction()` takes `mixed`, since the native `callable` autoloads the class
  of `'Foo::bar'`. A string that is not a name of PHP (`Internal\name()`) is a string literal, so a name from outside
  cannot inject a newline into a message.
- The parameter of a closure declared in a method reflects its function as a `\ReflectionMethod` named
  `{closure:...}`: `reflectedFunctionName()` checks for a closure before a method.

## Decisions

- Continues the older `thesis/formatter` (0.1.0) in the monorepo, the next series is 0.2.
- Values are written in the notation of PHPStan (`list{1, 2}`, `array{a: 1}`, `array{}` for an empty array,
  `...` for the rest) because readers of the errors know it. Exceptions where PHPStan has no notation:
  `object{...}` for `\stdClass`, a double-quoted literal, `'...'...` for a cut string (the dots outside
  the quotes, since inside they would look like content), `resource (closed)` of `get_debug_type()`.
- Only `\stdClass` itself shows its properties (`$value::class === \stdClass::class`), any other object, a subclass
  of `\stdClass` included, is its class only: its properties may hold secrets and are rarely what the error is about.
- A float is `var_export()`, so its digits follow the `serialize_precision` ini setting (exact by default):
  PHP has no formatting of a float independent of ini, and writing one is not worth it.
- An escaped character is `\u{...}`, decoded from its bytes without mbstring or intl: the package needs
  no extension. Escaping `\p{Cf}` breaks an emoji sequence joined by U+200D into its parts: accepted,
  a zero-width character in an error message is more often a trap than an emoji.
- A string is measured for the budget by its escaped length, a quote, a backslash and a dollar count as two
  bytes: the quoting is chosen after the cut, and one of the two quotings escapes them.
- The limits are constants, not parameters: nobody has needed another one. A parameter would also promise
  an exact bound, which `MAX_LENGTH` is not.
- `formatClass()` and `formatProperty()` take any string and `formatFunction()` any callable, string or pair
  in PHPDoc: an error is often about a class or a method that does not exist, a caller must not need
  `@phpstan-ignore` for it.
- Rejected: `formatReflectedType()` of the older package, `(string) $type` does the same and puts the parentheses
  of a DNF type (`(A&B)|C`), which it lost. Rejected: a parameter by position (`f($0)`), nobody used it.
- `formatValue()`, not `format()` of the older package: a bare `format` reads like a format string
  (`sprintf()`, `DateTime::format()`), and all the functions are `format<What>()`.
- The functions are in `Thesis\Formatter\`, unlike `Thesis\exceptionally()`: the package has an `Internal\`
  of its own instead of sharing `Thesis\Internal\` with exceptionally, and nine functions with common verbs
  do not take names in the root of the whole ecosystem.
