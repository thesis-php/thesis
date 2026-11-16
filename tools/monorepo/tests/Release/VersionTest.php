<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Release;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;
use Thesis\Monorepo\Internal\Release\Version;
use Thesis\Monorepo\Internal\Series;

#[Test]
final readonly class VersionTest
{
    /**
     * @param list<string> $expected
     */
    #[DataSet([null, ['0.1.0', '0.1.0-alpha1', '0.1.0-beta1', '0.1.0-RC1']])]
    #[DataSet(['0.1.3', ['0.1.4', '0.1.4-alpha1', '0.1.4-beta1', '0.1.4-RC1']])]
    #[DataSet(['v0.1.3', ['0.1.4', '0.1.4-alpha1', '0.1.4-beta1', '0.1.4-RC1']])]
    #[DataSet(['0.1.4-alpha2', ['0.1.4', '0.1.4-alpha3', '0.1.4-beta1', '0.1.4-RC1']])]
    #[DataSet(['0.1.4-beta1', ['0.1.4', '0.1.4-beta2', '0.1.4-RC1']])]
    #[DataSet(['0.1.4-RC3', ['0.1.4', '0.1.4-RC4']])]
    public static function comesRightAfterLastVersionOfSeries(?string $last, array $expected): void
    {
        $series = Series::parse('0.1');
        \assert($series !== null);

        $next = Version::next($series, $last === null ? null : Version::parse($last));

        Assert::same(array_map(static fn(Version $version) => $version->toString(), $next), $expected);
    }

    #[DataSet(['0.1'])]
    #[DataSet(['0.1.0-rc1'])]
    #[DataSet(['0.1.0-beta'])]
    #[DataSet(['0.1.0-beta0'])]
    #[DataSet(['01.1.0'])]
    #[DataSet(['0.1.0.0'])]
    #[DataSet(['0.1.0-patch1'])]
    public static function parsesOnlyVersionsThatReleaseWrites(string $version): void
    {
        Assert::null(Version::parse($version));
    }
}
