<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

/**
 * @api
 */
enum Version: string
{
    case Http10 = 'HTTP/1.0';
    case Http11 = 'HTTP/1.1';
}
