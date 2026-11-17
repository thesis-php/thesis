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

        @trigger_error('deprecated', E_USER_DEPRECATED);
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
