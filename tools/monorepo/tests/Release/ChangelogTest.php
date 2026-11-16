<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Release;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Release\Changelog;

#[Test]
final readonly class ChangelogTest
{
    public static function readsVersionUnderUnreleasedWithItsNotes(): void
    {
        $changelog = Changelog::parse(<<<'MD'
            # Changelog

            ## [Unreleased]

            ## [0.1.1] - 2026-11-16

            ### Fixed

            - A bug.

            ## [0.1.0] - 2026-11-01

            - The first version.

            [0.1.1]: https://github.com/thesis-php/a/compare/v0.1.0...v0.1.1
            MD, 'packages/a/CHANGELOG.md');

        ['version' => $version, 'notes' => $notes] = $changelog->top();

        Assert::same($changelog->unreleased, '');
        Assert::same($version->toString(), '0.1.1');
        Assert::same($notes, "### Fixed\n\n- A bug.");
    }

    public static function keepsLinkDefinitionsOutOfLastSection(): void
    {
        $changelog = Changelog::parse(<<<'MD'
            ## [Unreleased]

            ## [0.1.0] - 2026-11-01

            - The first version.

            [0.1.0]: https://github.com/thesis-php/a/releases/tag/v0.1.0
            MD, 'packages/a/CHANGELOG.md');

        Assert::same($changelog->top()['notes'], '- The first version.');
    }

    public static function readsChangesUnderUnreleased(): void
    {
        $changelog = Changelog::parse("## [Unreleased]\n\n- A change.\n", 'packages/a/CHANGELOG.md');

        Assert::same($changelog->unreleased, '- A change.');
        Assert::null($changelog->topVersion());
    }

    public static function failsWithoutUnreleasedFirst(): void
    {
        Expect::exception(MonorepoException::class)->withMessage(
            'packages/a/CHANGELOG.md: the first section must be ## [Unreleased].',
        );

        Changelog::parse("## [0.1.0] - 2026-11-01\n\n## [Unreleased]\n", 'packages/a/CHANGELOG.md');
    }

    #[DataSet(['## [0.1.0]'])]
    #[DataSet(['## [0.1.0] - soon'])]
    #[DataSet(['## [v0.1.0] - 2026-11-01'])]
    #[DataSet(['## [0.1] - 2026-11-01'])]
    #[DataSet(['## [0.1.0] - 2026-13-45'])]
    public static function failsOnHeadingThatIsNotRelease(string $heading): void
    {
        $changelog = Changelog::parse("## [Unreleased]\n\n{$heading}\n\n- A change.\n", 'packages/a/CHANGELOG.md');

        Expect::exception(MonorepoException::class);

        $changelog->top();
    }

    public static function readsNoHeadingInFencedCodeBlock(): void
    {
        $changelog = Changelog::parse(<<<'MD'
            ## [Unreleased]

            ## [0.1.1] - 2026-11-16

            - The heading is now written as:

              ```md
              ## [0.2.0] - 2026-11-16
              ```

            ## [0.1.0] - 2026-11-01

            - The first version.
            MD, 'packages/a/CHANGELOG.md');

        Assert::string($changelog->top()['notes'])->contains('## [0.2.0] - 2026-11-16');
    }

    public static function failsOnReleaseWithoutNotes(): void
    {
        $changelog = Changelog::parse("## [Unreleased]\n\n## [0.1.0] - 2026-11-01\n", 'packages/a/CHANGELOG.md');

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/a/CHANGELOG.md: [0.1.0] lists no changes, they are the notes of its release.',
        );

        $changelog->top();
    }
}
