<?php

declare(strict_types=1);

namespace Thesis\Logger\Formatter;

use Psr\Log\LogLevel;
use Thesis\Logger\Formatter;
use Thesis\Logger\Record;

/**
 * Formats a line with an RFC 3339 timestamp, a level label, a message and optional JSON context (RFC 8259).
 * Colored level labels use ECMA-48 Select Graphic Rendition (SGR) and the aixterm bright-black code for debug.
 *
 * @api
 * @see https://www.rfc-editor.org/rfc/rfc3339.html#section-5.6
 * @see https://www.rfc-editor.org/rfc/rfc8259.html
 * @see https://ecma-international.org/publications-and-standards/standards/ecma-48/
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
 */
final readonly class Console implements Formatter
{
    /**
     * @param bool $colors whether to emit ANSI colors for the output
     */
    public function __construct(
        private bool $colors = true,
    ) {}

    #[\Override]
    public function format(Record $record): string
    {
        $level = strtoupper($record->level);

        if ($this->colors) {
            $color = match ($record->level) {
                LogLevel::EMERGENCY => '1;35',
                LogLevel::ALERT, LogLevel::CRITICAL => '1;31',
                LogLevel::ERROR => '31',
                LogLevel::WARNING => '33',
                LogLevel::NOTICE => '36',
                LogLevel::INFO => '32',
                LogLevel::DEBUG => '90',
            };
            $level = "\033[{$color}m{$level}\033[0m";
        }

        $time = $record->time->format('Y-m-d\TH:i:s.up');
        $message = addcslashes($record->message, "\0..\37\177");
        $line = "[{$time}] {$level} {$message}";

        if ($record->context !== []) {
            $flags = JSON_THROW_ON_ERROR
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES;
            $line .= ' ' . json_encode((object) $record->context, $flags);
        }

        return $line;
    }
}
