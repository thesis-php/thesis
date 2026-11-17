<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use function Thesis\Async\group;

/**
 * PhpErrorsPlugin is in testo.php of the monorepo for every suite, this one too.
 */
#[Test]
final class PhpErrorsTest
{
    public function throwsWarning(): void
    {
        Expect::exception(\ErrorException::class)->withMessage('boom');

        trigger_error('boom', E_USER_WARNING);
    }

    public function throwsDeprecation(): void
    {
        Expect::exception(\ErrorException::class)->withMessage('deprecated');
        // A variable: error_suppression of the fixer puts @ before trigger_error() of E_USER_DEPRECATED.
        $deprecation = E_USER_DEPRECATED;
        // Infection runs the tests without deprecations in error_reporting.
        $reporting = error_reporting(E_ALL);

        try {
            trigger_error('deprecated', $deprecation);
        } finally {
            error_reporting($reporting);
        }
    }

    public function throwsWarningOfTaskStartedByTest(): void
    {
        Expect::exception(\ErrorException::class)->withMessage('boom');

        group(static fn(Group $group) => $group->spawn(static fn() => trigger_error('boom', E_USER_WARNING))->await());
    }

    public function skipsSuppressedError(): void
    {
        error_clear_last();

        @trigger_error('suppressed', E_USER_WARNING);

        Assert::same(error_get_last()['message'] ?? null, 'suppressed');
    }
}
