<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * @api
 */
enum DeliveryMode: int
{
    case Transient = 1;

    /**
     * Written to disk by a durable queue, so that it survives a restart of the broker.
     */
    case Persistent = 2;
}
