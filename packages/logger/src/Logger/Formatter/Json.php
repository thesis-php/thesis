<?php

declare(strict_types=1);

namespace Thesis\Logger\Formatter;

use Thesis\Logger\Formatter;
use Thesis\Logger\Record;

/**
 * Formats each record as one UTF-8 JSON object (RFC 8259 and ECMA-404) with an RFC 3339 timestamp.
 * Logger adds the newline for NDJSON and JSON Lines streams. Thesis defines the object fields.
 *
 * @api
 * @see https://www.rfc-editor.org/rfc/rfc8259.html
 * @see https://ecma-international.org/publications-and-standards/standards/ecma-404/
 * @see https://www.rfc-editor.org/rfc/rfc3339.html#section-5.6
 * @see https://github.com/ndjson/ndjson-spec#31-serialization
 * @see https://jsonlines.org/
 */
final class Json implements Formatter
{
    #[\Override]
    public function format(Record $record): string
    {
        return json_encode([
            'time' => $record->time->format('Y-m-d\TH:i:s.up'),
            'level' => $record->level,
            'message' => $record->message,
            'context' => (object) $record->context,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
