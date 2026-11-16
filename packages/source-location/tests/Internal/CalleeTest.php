<?php

declare(strict_types=1);

namespace Thesis\SourceLocation\Internal;

use Testo\Assert;
use Testo\Test;

#[Test]
final class CalleeTest
{
    public function namesMethod(): void
    {
        Assert::same(callee('send', 'App\Client'), 'App\Client::send');
    }

    public function cutsMethodOfAnonymousClassWhosePathLooksLikeItsEnd(): void
    {
        Assert::same(callee('m', "class@anonymous\0/app/a:1\$b/lib.php:3\$1f"), 'class@anonymous::m');
    }

    public function cutsClosureOfAnonymousClassWhosePathHasNewline(): void
    {
        Assert::same(
            actual: callee("{closure:class@anonymous\0/app/a\nb/lib.php:12\$1f::make():15}", 'whatever'),
            expected: '{closure:class@anonymous::make():15}',
        );
    }

    public function cutsClosureOfAnonymousClassWhosePathLooksLikeItsEnd(): void
    {
        Assert::same(
            actual: callee("{closure:App\\Base@anonymous\0/app/x:7\$ff.php:12\$a::make():15}", 'whatever'),
            expected: '{closure:App\Base@anonymous::make():15}',
        );
    }

    public function dropsNulIfPatternFails(): void
    {
        $limit = ini_set('pcre.backtrack_limit', '1');

        try {
            $callee = callee("{closure:class@anonymous\0/app/lib.php:12\$1f::make():15}", 'whatever');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        Assert::same($callee, '{closure:class@anonymous');
    }
}
