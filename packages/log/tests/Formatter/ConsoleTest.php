<?php

declare(strict_types=1);

namespace Thesis\Log\Formatter;

use Psr\Log\LogLevel;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Thesis\Log\Record;

#[Test]
#[Covers(Console::class)]
final class ConsoleTest
{
    public function colorsOnlyTheLevelLabel(): void
    {
        $time = new \DateTimeImmutable('2026-11-23T19:12:00.012345+00:00');
        $formatter = new Console();
        $colors = [
            LogLevel::DEBUG => '90',
            LogLevel::INFO => '32',
            LogLevel::NOTICE => '36',
            LogLevel::WARNING => '33',
            LogLevel::ERROR => '31',
            LogLevel::CRITICAL => '1;31',
            LogLevel::ALERT => '1;31',
            LogLevel::EMERGENCY => '1;35',
        ];

        foreach ($colors as $level => $color) {
            $levelLabel = strtoupper($level);
            $line = $formatter->format(new Record(time: $time, level: $level, message: 'Ready', context: []));

            Assert::same($line, "[2026-11-23T19:12:00.012345Z] \033[{$color}m{$levelLabel}\033[0m Ready");
        }
    }

    public function escapesControlCharactersAndCanOmitColors(): void
    {
        $record = new Record(
            time: new \DateTimeImmutable('2026-11-23T22:12:00.012345+03:00'),
            level: LogLevel::WARNING,
            message: "HTTP failed\n\033[2J",
            context: ['status' => 503, 'input' => "\r\033[2J", 'path' => "/café/\xff"],
        );

        $line = new Console(colors: false)->format($record);

        Assert::same($line, '[2026-11-23T22:12:00.012345+03:00] WARNING HTTP failed\n\033[2J '
            . '{"status":503,"input":"\r\u001b[2J","path":"/café/' . "\u{FFFD}" . '"}');
    }
}
