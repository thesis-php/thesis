<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Requirements;

#[Test]
final readonly class RequirementsTest
{
    public static function takesNarrowestConstraintIncludingPhp(): void
    {
        $fixture = new Fixture()
            ->package('a', "require: ['php' => '^8.5.2', 'psr/clock' => '^1.0']")
            ->package('b', "requireDev: ['psr/clock' => '^1.1', 'psr/log' => '^3.0']")
        ;

        $requirements = Requirements::resolve($fixture->load());

        Assert::same($requirements->require, ['php' => '^8.5.2', 'psr/clock' => '^1.1']);
        Assert::same($requirements->requireDev, ['psr/log' => '^3.0']);
    }

    public static function ignoresMonorepoPackages(): void
    {
        $fixture = new Fixture()
            ->package('bytes')
            ->package('time', "require: ['thesis/bytes' => '*']")
        ;

        $requirements = Requirements::resolve($fixture->load());

        Assert::same($requirements->require, ['php' => '^8.5']);
    }

    public static function acceptsConstraintsWithinOneAnother(): void
    {
        $fixture = new Fixture()
            ->package('a', "require: ['psr/clock' => '^1.0']")
            ->package('b', "requireDev: ['psr/clock' => '^1.1']")
        ;

        $requirements = Requirements::resolve($fixture->load());

        Assert::same($requirements->require, ['php' => '^8.5', 'psr/clock' => '^1.1']);
    }

    public static function rejectsConstraintsWithoutCommonVersion(): void
    {
        $fixture = new Fixture()
            ->package('a', "require: ['psr/clock' => '^1.0']")
            ->package('b', "requireDev: ['psr/clock' => '^2.0']")
        ;

        Expect::exception(MonorepoException::class)->withMessage(
            'No version of psr/clock satisfies every constraint: ^1.0 in packages/a; ^2.0 in packages/b.',
        );

        Requirements::resolve($fixture->load());
    }

    public static function rejectsIntersectingConstraintsWithoutNarrowest(): void
    {
        $fixture = new Fixture()
            ->package('a', "require: ['psr/clock' => '^1.0 || ^2.0']")
            ->package('b', "require: ['psr/clock' => '^2.0 || ^3.0']")
        ;

        Expect::exception(MonorepoException::class)->withMessage(
            'Constraints of psr/clock intersect, but none lies within all the others, narrow one to the intersection: ^1.0 || ^2.0 in packages/a; ^2.0 || ^3.0 in packages/b.',
        );

        Requirements::resolve($fixture->load());
    }

    public static function rejectsInvalidConstraint(): void
    {
        $fixture = new Fixture()->package('a', "require: ['psr/clock' => 'one']");

        Expect::exception(MonorepoException::class)->withMessage(
            'psr/clock: packages/a requires one, could not parse version constraint one: Invalid version string "one".',
        );

        Requirements::resolve($fixture->load());
    }
}
