<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * @api
 */
enum AuthMechanism: string
{
    case Plain = 'PLAIN';
    case AmqPlain = 'AMQPLAIN';

    /**
     * Authentication by the client certificate of TLS (rabbitmq_auth_mechanism_ssl): no user and password are sent.
     */
    case External = 'EXTERNAL';
}
