<?php

declare(strict_types=1);

namespace Thesis\SourceLocation;

use Testo\Assert;
use Testo\Test;
use Thesis\SourceLocation\Library\Library;

#[Test]
final class CallerTest
{
    public function findsCallOfLibrary(): void
    {
        $caller = Library::call();
        $line = __LINE__ - 1;

        Assert::equals($caller, new Caller(new Location(__FILE__, $line), Library::class . '::call'));
    }

    public function findsCallWrittenInEval(): void
    {
        $caller = eval('return \Thesis\SourceLocation\Library\Library::call();');
        $line = __LINE__ - 1;

        Assert::equals($caller, new Caller(new Location(__FILE__, $line), Library::class . '::call'));
    }

    public function namesClosureCalledFromLibrary(): void
    {
        $caller = Library::callBack(static fn() => Library::call());

        Assert::same($caller?->location->file, __FILE__);
        Assert::same($caller->location->line, __LINE__ - 3);
        Assert::same($caller->callee, Library::class . '::call');
    }

    public function looksThroughLimitedFrames(): void
    {
        Assert::null(Library::callWithLimit(1));
        $caller = Library::callWithLimit(2);
        $line = __LINE__ - 1;

        Assert::equals($caller, new Caller(new Location(__FILE__, $line), Library::class . '::callWithLimit'));
    }

    public function returnsNullIfEveryCallIsSkipped(): void
    {
        Assert::null(Library::callSkippingEverything());
    }

    public function isFormattedAsCalleeAndLocation(): void
    {
        Assert::same(
            actual: (string) new Caller(new Location('/app/src/foo.php', 12), 'App\Client::send'),
            expected: 'App\Client::send() at /app/src/foo.php:12',
        );
    }
}
