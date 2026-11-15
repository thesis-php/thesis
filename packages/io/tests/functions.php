<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * Every byte until the end of the stream.
 */
function readAll(Reader $reader): string
{
    $bytes = '';

    while (true) {
        try {
            $bytes .= $reader->read(65_536);
        } catch (UnexpectedEof) {
            return $bytes;
        }
    }
}
