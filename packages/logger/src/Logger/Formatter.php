<?php

declare(strict_types=1);

namespace Thesis\Logger;

/**
 * Formats a log record for the writer task.
 *
 * @api
 */
interface Formatter
{
    /**
     * Returns one line with no line terminator. Logger adds the newline before writing.
     *
     * @throws \Throwable if the formatter cannot produce a line
     */
    public function format(Record $record): string;
}
