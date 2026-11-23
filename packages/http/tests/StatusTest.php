<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Status::class)]
final class StatusTest
{
    public function reasonPhraseForKnownCode(): void
    {
        Assert::same(Status::reasonPhrase(Status::NOT_FOUND), 'Not Found');
    }

    public function unknownCodeHasNoReasonPhrase(): void
    {
        Assert::null(Status::reasonPhrase(299));
    }
}
