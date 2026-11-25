# Candidates for automated code checks

- **Exhaustive enum matches (PHPStan).** Report a `default` arm when the subject has one concrete enum type.
  Naming each case lets PHPStan report a missing arm when the enum grows. Leave the choice of result for each
  case to the author. Keep open types and `match (true)` outside this first check.
  [PHP rules](../agents/php.md)
- **AMQP replies (PHPStan).** When the client constructs an outgoing protocol method, resolve its `noWait`
  argument by parameter and require the constant value `false`, including when passed by position or default.
  The client needs the reply to attribute a broker error to its call. The codec and its protocol fixtures
  must still represent both bit values. [AMQP invariants](../packages/amqp/AGENTS.md)
- **Trait declarations (PHPStan).** Report a trait declaration in project code, including test helpers.
  A source string containing a trait in the monorepo generator tests is valid input data.
  [Class design](../agents/php-objects.md) and [test helpers](../agents/tests.md)
- **Parent directories (Rector).** If this pattern recurs in review, replace a literal parent path such as
  `__DIR__ . '/../../foo'` with `\dirname(__DIR__, 2) . '/foo'`. Count whole leading `..` path segments.
  Preserve the suffix and skip dynamic paths. [PHP rules](../agents/php.md)
