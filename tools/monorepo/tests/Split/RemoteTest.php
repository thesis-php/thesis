<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Remote;

#[Test]
final readonly class RemoteTest
{
    /**
     * The arguments of a process are visible to everyone in ps, its environment only to its user.
     */
    public static function movesCredentialsFromUrlToEnvironment(): void
    {
        $remote = Remote::parse('https://x-access-token:secret@github.com/thesis-php');

        Assert::same($remote->url('async'), 'https://github.com/thesis-php/async.git');
        Assert::same($remote->env, [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'url.https://x-access-token:secret@github.com/thesis-php/.insteadOf',
            'GIT_CONFIG_VALUE_0' => 'https://github.com/thesis-php/',
        ]);
    }

    public static function needsNoEnvironmentWithoutCredentials(): void
    {
        $remote = Remote::parse('file:///tmp/remotes/');

        Assert::same($remote->url('async'), 'file:///tmp/remotes/async.git');
        Assert::same($remote->env, []);
    }

    public static function failsOnInvalidUrl(): void
    {
        Expect::exception(MonorepoException::class)->withMessage(
            'SPLIT_REMOTE is not a URL, expected e.g. https://x-access-token:<token>@github.com/thesis-php.',
        );

        Remote::parse('github.com/thesis-php');
    }
}
