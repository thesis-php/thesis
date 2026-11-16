<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Release;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\Release\Refs;
use Thesis\Monorepo\Internal\Release\Version;

#[Test]
final readonly class RefsTest
{
    private const string A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string C = 'cccccccccccccccccccccccccccccccccccccccc';

    public static function takesCommitOfAnnotatedTag(): void
    {
        $refs = Refs::parse(self::A . "\trefs/tags/v0.1.0\n" . self::B . "\trefs/tags/v0.1.0^{}\n", 'thesis-php/a');

        Assert::same($refs->tags, [['name' => 'v0.1.0', 'commit' => self::B, 'normalized' => '0.1.0.0']]);
    }

    public static function readsTagsWithAndWithoutVAsOneVersion(): void
    {
        $refs = Refs::parse(self::A . "\trefs/tags/0.1.0\n", 'thesis-php/a');

        Assert::same(array_column($refs->tagsOf(self::version('0.1.0')), 'name'), ['0.1.0']);
    }

    public static function skipsTagsThatAreNoVersions(): void
    {
        $refs = Refs::parse(self::A . "\trefs/tags/latest\n" . self::B . "\trefs/tags/v0.1\n", 'thesis-php/a');

        Assert::same(array_column($refs->tags, 'name'), ['v0.1']);
    }

    public static function takesHighestTagWithStableAfterItsPreReleases(): void
    {
        $refs = Refs::parse(
            self::A . "\trefs/tags/v0.1.0-RC1\n"
                . self::B . "\trefs/tags/v0.1.0\n"
                . self::C . "\trefs/tags/v0.1.1-beta1\n",
            'thesis-php/a',
        );

        Assert::same($refs->highest('0.1.*', stable: false)['name'] ?? null, 'v0.1.1-beta1');
        Assert::same($refs->highest('0.1.*', stable: true)['name'] ?? null, 'v0.1.0');
        Assert::null($refs->highest('^0.2', stable: false));
    }

    private static function version(string $version): Version
    {
        $parsed = Version::parse($version);
        \assert($parsed !== null);

        return $parsed;
    }
}
