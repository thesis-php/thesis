<?php

declare(strict_types=1);

namespace Thesis\Log\Formatter;

use Psr\Log\LogLevel;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Thesis\Log\Record;

#[Test]
#[Covers(Json::class)]
final class JsonTest
{
    public function preservesFieldTypesAndEscapesNewlines(): void
    {
        $record = new Record(
            time: new \DateTimeImmutable('2026-11-23T19:12:00.012345Z'),
            level: LogLevel::WARNING,
            message: "HTTP returned 503\nNext line",
            context: ['status' => 503, 'available' => false, 'load' => 1.5, 'peer' => null],
        );

        $line = new Json()->format($record);

        Assert::same($line, '{"time":"2026-11-23T19:12:00.012345Z","level":"warning",'
            . '"message":"HTTP returned 503\nNext line",'
            . '"context":{"status":503,"available":false,"load":1.5,"peer":null}}');
    }

    public function replacesInvalidUtf8AndUsesAnObjectForEmptyContext(): void
    {
        $record = new Record(
            time: new \DateTimeImmutable('2026-11-23T22:12:00.012345+03:00'),
            level: LogLevel::INFO,
            message: "Invalid byte at /заказ: \xff",
            context: [],
        );

        $line = new Json()->format($record);

        Assert::same($line, '{"time":"2026-11-23T22:12:00.012345+03:00","level":"info",'
            . "\"message\":\"Invalid byte at /заказ: \u{FFFD}\",\"context\":{}}");
    }
}
