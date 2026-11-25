<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Status;

/**
 * @api
 */
enum RouteFailure: int
{
    case BadPath = Status::BAD_REQUEST;
    case NotFound = Status::NOT_FOUND;
}
