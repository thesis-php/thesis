<?php

declare(strict_types=1);

namespace Thesis\Logger;

/**
 * Formats a log record for stream output.
 *
 * @api
 */
interface Formatter
{
    /**
     * Returns the formatted record. StreamHandler appends a newline before writing.
     *
     * @throws \Throwable if the formatter cannot format the record
     */
    public function format(Record $record): string;
}
