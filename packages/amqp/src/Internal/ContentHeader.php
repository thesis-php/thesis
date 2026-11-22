<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\Internal\Protocol\BasicProperties;
use Thesis\Io\Buffer;

/**
 * The payload of a content header frame: what follows a basic.deliver, basic.get-ok or basic.return.
 *
 * @internal
 */
final readonly class ContentHeader
{
    public static function decode(Buffer $buffer): self
    {
        // The class id is always basic, and the weight is unused.
        $buffer->skip(4);
        $bodySize = $buffer->readUint64();

        return new self($bodySize, BasicProperties::decode($buffer));
    }

    /**
     * @param non-negative-int $bodySize
     */
    public function __construct(
        public int $bodySize,
        public BasicProperties $properties,
    ) {}
}
