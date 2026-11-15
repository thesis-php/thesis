<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * The server has cancelled the consumer: its queue was deleted, or the node of a quorum queue failed over.
 *
 * @api
 */
final class ConsumerCancelled extends AmqpException {}
