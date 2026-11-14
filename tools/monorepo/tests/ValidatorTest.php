<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Validator;

#[Test]
final readonly class ValidatorTest
{
    public function acceptsPackageUsingLowerLayer(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->package('async', "layer: Layer::Runtime, uses: ['bytes'], require: ['revolt/event-loop' => '^1.0']")
            ->file('packages/async/src/Task.php', '<?php namespace Thesis\Async; use Thesis\Bytes\Buffer; final class Task {}');

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, []);
    }

    public function rejectsDependencyOnHigherLayer(): void
    {
        $fixture = new Fixture()
            ->package('async', 'layer: Layer::Runtime')
            ->package('bytes', "layer: Layer::Primitive, uses: ['async']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/bytes (layer primitive) uses async (layer runtime): a package may not depend on a higher layer.']);
    }

    public function rejectsCodeUsingPackageMissingInUses(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->package('time', 'layer: Layer::Primitive')
            ->file('packages/time/src/Clock.php', "<?php\n\nnamespace Thesis\\Time;\n\nuse Thesis\\Bytes\\Buffer;\n");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/time/src/Clock.php:5: uses Thesis\Bytes\Buffer from bytes, which is not in "uses".']);
    }

    public function allowsTestsToUseUsesDev(): void
    {
        $fixture = new Fixture()
            ->package('bytes', 'layer: Layer::Primitive')
            ->package('time', "layer: Layer::Primitive, usesDev: ['bytes']")
            ->file('packages/time/tests/ClockTest.php', '<?php namespace Thesis\Time; use Thesis\Bytes\Buffer;');

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, []);
    }

    public function rejectsDifferentConstraintsOfOneDependency(): void
    {
        $fixture = new Fixture()
            ->package('a', "layer: Layer::Primitive, require: ['psr/clock' => '^1.0']")
            ->package('b', "layer: Layer::Primitive, requireDev: ['psr/clock' => '^1.1']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['Use one constraint for psr/clock across the monorepo, found: ^1.0 in packages/a; ^1.1 in packages/b.']);
    }

    public function rejectsAmphpInRequireButNotInRequireDev(): void
    {
        $fixture = new Fixture()
            ->package('io', "layer: Layer::Io, require: ['amphp/socket' => '^2.0'], requireDev: ['amphp/amp' => '^3.0']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, [
            'packages/io: requires amphp/socket, but the ecosystem is built on revolt/event-loop only (amphp is fine in requireDev for interop tests).',
        ]);
    }

    public function rejectsCycle(): void
    {
        $fixture = new Fixture()
            ->package('a', "layer: Layer::Primitive, uses: ['b']")
            ->package('b', "layer: Layer::Primitive, uses: ['a']");

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['Dependency cycle: a -> b -> a.']);
    }

    public function rejectsClaudeMd(): void
    {
        $fixture = new Fixture()
            ->package('a', 'layer: Layer::Primitive')
            ->file('packages/a/CLAUDE.md', '@AGENTS.md');

        $errors = Validator::validate($fixture->load());

        Assert::same($errors, ['packages/a/CLAUDE.md must not exist: write AGENTS.md, a CLAUDE.md makes Claude Code ignore every AGENTS.md.']);
    }
}
