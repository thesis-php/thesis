<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Thesis\Io\Buffer;

/**
 * A method of the protocol, generated from the specification: the payload of a method frame.
 *
 * @internal
 */
interface Method
{
    public const int CLASS_ID = 0;
    public const int METHOD_ID = 0;

    /**
     * Writes the class id, the method id and the fields.
     */
    public function encode(Buffer $buffer): void;
}
