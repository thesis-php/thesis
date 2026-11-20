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

    /**
     * The task runs while the test is suspended, when the guards of Testo outer to the loop would step aside:
     * the one of its error handler takes the handlers of the test off the stack.
     */
    #[EventLoop]
    public function handlerOfTestSeesErrorOfTaskStartedByTest(): void
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        });

        try {
            group(static function (Group $group) {
                $group->spawn(static fn() => trigger_error('boom', E_USER_WARNING))->await();
            });
        } finally {
            restore_error_handler();
        }

        Assert::same($messages, ['boom']);
    }

    public function skipsSuppressedError(): void
    {
        error_clear_last();

        @trigger_error('suppressed', E_USER_WARNING);

        Assert::same(error_get_last()['message'] ?? null, 'suppressed');
    }
}
