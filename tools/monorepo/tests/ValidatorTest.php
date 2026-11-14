<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\Validator;

#[Test]
final readonly class ValidatorTest
{
    public static function acceptsPackageUsingLowerLayer(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->package('async', "layer: Layer::Runtime, uses: ['bytes'], require: ['revolt/event-loop' => '^1.0']")
            ->file(
                'packages/async/src/Task.php',
                '<?php namespace Thesis\Async; use Thesis\Bytes\Buffer; /** @api */ final class Task {}',
            );

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, []);
    }

    public static function rejectsDependencyOnHigherLayer(): void
    {
        $fixture = new Fixture()
            ->package('async', 'layer: Layer::Runtime')
            ->package('bytes', "layer: Layer::Primitive, uses: ['async']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/bytes (layer primitive) uses async (layer runtime): a package may not depend on a higher layer.']);
    }

    public static function rejectsCodeUsingPackageMissingInUses(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->package('time', 'layer: Layer::Primitive')
            ->file('packages/time/src/Clock.php', "<?php\n\nnamespace Thesis\\Time;\n\nuse Thesis\\Bytes\\Buffer;\n");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/time/src/Clock.php:5: uses Thesis\Bytes\Buffer from bytes, which is not in "uses".']);
    }

    public static function allowsTestsToUseUsesDev(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->package('time', "layer: Layer::Primitive, usesDev: ['bytes']")
            ->file('packages/time/tests/ClockTest.php', '<?php namespace Thesis\Time; use Thesis\Bytes\Buffer;');

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, []);
    }

    public static function rejectsDifferentConstraintsOfOneDependency(): void
    {
        $fixture = new Fixture()
            ->package('a', "layer: Layer::Primitive, require: ['psr/clock' => '^1.0']")
            ->package('b', "layer: Layer::Primitive, requireDev: ['psr/clock' => '^1.1']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['Use one constraint for psr/clock across the monorepo, found: ^1.0 in packages/a; ^1.1 in packages/b.']);
    }

    public static function rejectsAmphpInRequireButNotInRequireDev(): void
    {
        $fixture = new Fixture()
            ->package('io', <<<'PHP'
                layer: Layer::Io,
                require: ['amphp/socket' => '^2.0'],
                requireDev: ['amphp/amp' => '^3.0'],
                PHP);

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, [
            'packages/io: requires amphp/socket, but the ecosystem is built on revolt/event-loop only (amphp is fine in requireDev for interop tests).',
        ]);
    }

    public static function rejectsCycle(): void
    {
        $fixture = new Fixture()
            ->package('a', "layer: Layer::Primitive, uses: ['b']")
            ->package('b', "layer: Layer::Primitive, uses: ['a']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['Dependency cycle: a -> b -> a.']);
    }

    public static function rejectsClaudeMd(): void
    {
        $fixture = new Fixture()
            ->package('a', 'layer: Layer::Primitive')
            ->file('packages/a/CLAUDE.md', '@AGENTS.md');

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/a/CLAUDE.md must not exist: write AGENTS.md, a CLAUDE.md makes Claude Code ignore every AGENTS.md.']);
    }

    public static function acceptsApiAndInternalSymbolsInTheirNamespaces(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->file('packages/bytes/src/Buffer.php', <<<'PHP'
                <?php

                namespace Thesis\Bytes;

                /**
                 * @api
                 */
                #[\Attribute]
                final readonly class Buffer
                {
                    public function __construct()
                    {
                        $this->x = new class {};
                    }
                }

                /**
                 * @api
                 */
                function buffer(): Buffer
                {
                    return new Buffer();
                }
                PHP)
            ->file('packages/bytes/src/Internal/Chunk.php', <<<'PHP'
                <?php

                namespace Thesis\Bytes\Internal;

                /**
                 * @internal
                 */
                enum Chunk
                {
                    case Small;
                }

                /**
                 * @internal
                 */
                const SIZE = 8192;
                PHP);

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, []);
    }

    public static function rejectsSymbolMarkedNeitherApiNorInternal(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->file('packages/bytes/src/Buffer.php', <<<'PHP'
                <?php

                namespace Thesis\Bytes;

                /**
                 * A growable buffer.
                 */
                final class Buffer {}

                function buffer(): Buffer
                {
                    return new Buffer();
                }
                PHP);

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, [
            'packages/bytes/src/Buffer.php:8: mark Thesis\Bytes\Buffer either @api or @internal.',
            'packages/bytes/src/Buffer.php:10: mark Thesis\Bytes\buffer either @api or @internal.',
        ]);
    }

    public static function rejectsSymbolMarkedBothApiAndInternal(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->file('packages/bytes/src/Buffer.php', "<?php\n\nnamespace Thesis\\Bytes;\n\n/**\n * @api\n * @internal\n */\ninterface Buffer {}\n");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/bytes/src/Buffer.php:9: mark Thesis\Bytes\Buffer either @api or @internal, not both.']);
    }

    public static function rejectsInternalSymbolOutsideInternalNamespace(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->file('packages/bytes/src/Chunk.php', "<?php\n\nnamespace Thesis\\Bytes;\n\n/**\n * @internal\n */\ntrait Chunk {}\n");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/bytes/src/Chunk.php:8: Thesis\Bytes\Chunk is @internal, move it to an Internal namespace.']);
    }

    public static function rejectsApiSymbolInsideInternalNamespace(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->file('packages/bytes/src/Internal/Chunk.php', "<?php\n\nnamespace Thesis\\Bytes\\Internal;\n\n/**\n * @api\n */\nfinal class Chunk {}\n");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/bytes/src/Internal/Chunk.php:8: Thesis\Bytes\Internal\Chunk is in an Internal namespace, mark it @internal.']);
    }
}
