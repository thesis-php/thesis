<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Io\ReadFailed;

#[Test]
final class FileBodyTest
{
    public static function getsTheSizeFromTheFileItStreams(): void
    {
        $body = new FileBody(__FILE__);

        try {
            Assert::same($body->read(5), '<?php');
            Assert::same($body->size, filesize(__FILE__));
        } finally {
            $body->close();
        }
    }

    public static function rejectsADirectory(): void
    {
        Expect::exception(ReadFailed::class);
        new FileBody(__DIR__);
    }
}
