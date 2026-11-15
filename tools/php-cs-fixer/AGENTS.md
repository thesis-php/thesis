# tools/php-cs-fixer

Custom rules of PHP CS Fixer for `tools/php-cs-fixer.php`.

- PHP CS Fixer is installed globally in the Docker image, not in `vendor/`: the config loads every rule
  with `require_once`, and the rules are not in the autoloader of the monorepo. PHPStan sees the classes
  of PHP CS Fixer, the tests do not.
- A test runs the `php-cs-fixer` binary with the real config over `tests/Fixture/*.php.inc` (the input, `-----`,
  the expected output; no separator means the input must stay as is), all fixtures in one run.
  It also runs the expected output, which must not change: `make fixer-check` right after `make fixer` must pass.
  A rule is correct only together with the rules around it, so a fixture is the whole file, not one token.
  `.php.inc` keeps the fixtures away from PHP CS Fixer, Rector and PHPStan.
- The tests run on every PHP version of CI, PHP CS Fixer refuses a version newer than it supports:
  the test passes `--allow-unsupported-php-version=yes`, and the fixtures keep to the syntax of the oldest version.

## Thesis/static_call_chain

A multiline chain that starts with a static call breaks the line before `::` and before every next operator,
one level deeper than the line of the class.

- Priority `-1`: after `no_space_around_double_colon` (`1`), which joins `Foo\n::create()`, and after
  `method_chaining_indentation` (`0`), so both see `Foo::create()` on one line. `method_chaining_indentation`
  indents from the previous line: given `::create()` on its own line, it moves the next calls and their
  multiline arguments one level too deep, and nothing indents the arguments back. Before `statement_indentation`
  (`-3`), which indents the arguments of the static call from its new line.
  Rejected: our own replacement of `no_space_around_double_colon` that keeps the line break before `::`,
  it runs into exactly that.
- A chain is multiline when any of its operators (`->`, `?->`, `::`) starts a line. The chain is followed through
  calls, property fetches and `[...]`, and ends at a dynamic name. Only a class name starts one: `Foo`, `Foo\Bar`,
  `self`, `static`, `parent`, not `$foo::`, `new Foo()::` or `$foo->bar::`.
- `make fix` runs Rector before PHP CS Fixer: Rector prints a chain it changes as `Foo::create()->...`.
