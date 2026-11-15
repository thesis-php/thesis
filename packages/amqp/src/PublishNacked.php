<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * The broker has not taken the message in confirm mode (basic.nack): an internal error of a queue,
 * or `x-overflow: reject-publish` of a full queue. Publishing it again may succeed.
 *
 * @api
 */
final class PublishNacked extends AmqpException {}
