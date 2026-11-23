<?php

declare(strict_types=1);

namespace Thesis\Http\Internal;

use Thesis\Http\Headers;
use Thesis\Http\Request;
use Thesis\Http\Response;

/** @internal */
const EMPTY_HEADERS = new Headers();

/**
 * The characters allowed in a token (RFC 9110). A method and a field name are tokens.
 *
 * @internal
 */
const TOKEN_CHARS = '!#$%&\'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/**
 * The bytes forbidden in a field value: DEL and every control character except the tab.
 *
 * @internal
 */
const FIELD_VALUE_FORBIDDEN_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F"
    . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

/**
 * @internal
 * @phpstan-assert-if-true =non-empty-string $value
 */
function isToken(string $value): bool
{
    return $value !== '' && strspn($value, TOKEN_CHARS) === \strlen($value);
}

/**
 * @internal
 */
function isFieldValue(string $value): bool
{
    return strcspn($value, FIELD_VALUE_FORBIDDEN_CHARS) === \strlen($value);
}

/**
 * A peer may send a line that is kilobytes long: an error message shows only the start of the line.
 *
 * @internal
 */
const QUOTE_LENGTH = 64;

/**
 * Prepares bytes from a peer or a caller for an error message: control characters are escaped,
 * so they never get into a log unescaped.
 *
 * @internal
 */
function quote(string $bytes): string
{
    if (\strlen($bytes) > QUOTE_LENGTH) {
        $bytes = substr($bytes, 0, QUOTE_LENGTH) . '...';
    }

    return '"' . addcslashes($bytes, "\0..\37\"\\\177..\377") . '"';
}

/**
 * @internal
 */
function discardBodyAndReadTrailers(Request|Response $message): Headers
{
    while ($message->body->read(8192) !== null);

    return $message->trailers ?? throw new \LogicException('Trailers are unavailable after the body ended.');
}
