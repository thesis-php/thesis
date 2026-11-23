<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\Delivery;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Async\Deferred;
use Thesis\Async\Lock;

/**
 * A synchronous method of a channel that waits for its reply. It holds the lock of the channel's calls until the reply
 * comes, even if the caller was cancelled meanwhile, so that the reply never goes to the next method.
 *
 * @internal
 */
final readonly class PendingCall
{
    /**
     * @var Deferred<Method|Delivery|null>
     */
    public Deferred $reply;

    /**
     * @param list<class-string<Method>> $replies
     * @param bool $getNoAck whether the delivery of a basic.get needs no ack
     */
    public function __construct(
        private array $replies,
        private Lock $lock,
        public bool $getNoAck = false,
        public ?ConsumerState $cancelled = null,
    ) {
        $this->reply = new Deferred();
    }

    public function expects(Method $frame): bool
    {
        return \in_array($frame::class, $this->replies, true);
    }

    public function complete(null|Method|Delivery $value): void
    {
        $this->reply->complete($value);
        $this->lock->release();
    }

    public function fail(\Throwable $reason): void
    {
        $this->reply->fail($reason);
        $this->lock->release();
    }
}
