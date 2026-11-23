<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\AmqpException;
use Thesis\Amqp\Internal\Protocol\BasicReturn;
use Thesis\Amqp\Message;
use Thesis\Amqp\MessageReturned;
use Thesis\Amqp\PublishNacked;
use Thesis\Amqp\ReplyCode;
use Thesis\Async\Deferred;

/**
 * The publisher confirms of a channel: the delivery tags of the publishes, the confirms that wait for the broker
 * and the returns of mandatory messages, which the broker sends before their confirm.
 *
 * @internal
 */
final class Confirms
{
    /**
     * Correlates a basic.return with its publish: the frame carries no delivery tag.
     */
    private const string RETURN_HEADER = 'x-thesis-return-id';

    private int $lastTag = 0;

    /**
     * @var array<int, Deferred<null>> by delivery tag, in ascending order
     */
    private array $unconfirmed = [];

    /**
     * @var array<int, string> the return id of a mandatory publish by its delivery tag
     */
    private array $returnIds = [];

    /**
     * @var array<string, int>
     */
    private array $returnTags = [];

    /**
     * @var array<int, MessageReturned> returned messages whose ack has not come yet
     */
    private array $returned = [];

    /**
     * Marks a mandatory message, so that its return finds its confirm.
     * The id goes first, so that a header of the same name does not replace it; `+` keeps numeric header names,
     * which a spread would renumber.
     *
     * @return array{Message, string} the message to publish and its return id
     */
    public static function returnable(Message $message): array
    {
        $returnId = bin2hex(random_bytes(8));

        return [clone($message, ['headers' => [self::RETURN_HEADER => $returnId] + $message->headers]), $returnId];
    }

    /**
     * Assigns the next delivery tags, in the order of publishing. The confirms are taken now, not looked up
     * after the write: the reader may settle one while the write waits.
     *
     * @template T
     * @param non-empty-list<array{T, ?string}> $publishes pairs of a publish and its return ID. The ID is null
     *     for a non-mandatory message.
     * @return non-empty-list<array{T, Deferred<null>}>
     */
    public function expect(array $publishes): array
    {
        $confirms = [];

        foreach ($publishes as [$publish, $returnId]) {
            $tag = ++$this->lastTag;
            /** @var Deferred<null> $confirm */
            $confirm = new Deferred();
            $this->unconfirmed[$tag] = $confirm;
            $confirms[] = [$publish, $confirm];

            if ($returnId !== null) {
                $this->returnIds[$tag] = $returnId;
                $this->returnTags[$returnId] = $tag;
            }
        }

        return $confirms;
    }

    /**
     * Gives the last $count tags back when nothing of their publishes reached the server: the next publish takes them.
     *
     * @param positive-int $count
     */
    public function giveBack(int $count): void
    {
        $first = $this->lastTag - $count + 1;

        for ($tag = $first; $tag <= $this->lastTag; ++$tag) {
            // A close already draining this tag can finish: the cancelled publish never reached the broker.
            ($this->unconfirmed[$tag] ?? null)?->complete();
            $this->forget($tag);
        }

        $this->lastTag = $first - 1;
    }

    /**
     * A basic.ack or basic.nack of the broker.
     */
    public function confirm(int $tag, bool $multiple, bool $nacked): void
    {
        if (!$multiple) {
            $this->settle($tag, $nacked);

            return;
        }

        foreach ($this->unconfirmed as $unconfirmed => $_) {
            if ($unconfirmed > $tag) {
                break;
            }

            $this->settle($unconfirmed, $nacked);
        }
    }

    /**
     * A basic.return with its message: kept until the confirm of the publish comes and fails with it.
     */
    public function receiveReturn(BasicReturn $return, Message $message): void
    {
        $returnId = $message->headers[self::RETURN_HEADER] ?? null;
        $tag = \is_string($returnId) ? $this->returnTags[$returnId] ?? null : null;

        if ($tag === null) {
            return;
        }

        $headers = $message->headers;
        unset($headers[self::RETURN_HEADER]);
        $this->returned[$tag] = new MessageReturned(
            replyCode: ReplyCode::tryFrom($return->replyCode) ?? ReplyCode::NoRoute,
            replyText: $return->replyText,
            exchange: $return->exchange,
            routingKey: $return->routingKey,
            returnedMessage: clone($message, ['headers' => $headers]),
        );
    }

    /**
     * Waits for the confirms of the publishes in flight. Their failures are left to their publishers.
     */
    public function awaitAll(): void
    {
        foreach ($this->unconfirmed as $confirm) {
            try {
                $confirm->await();
            } catch (AmqpException) {
                // Its publisher gets it.
            }
        }
    }

    /**
     * Fails every confirm that waits: the channel is closed.
     */
    public function failAll(AmqpException $reason): void
    {
        $unconfirmed = $this->unconfirmed;
        $this->unconfirmed = [];
        $this->returnIds = [];
        $this->returnTags = [];
        $this->returned = [];

        foreach ($unconfirmed as $confirm) {
            $confirm->fail($reason);
        }
    }

    private function settle(int $tag, bool $nacked): void
    {
        $confirm = $this->unconfirmed[$tag] ?? null;
        $returned = $this->returned[$tag] ?? null;
        $this->forget($tag);

        match (true) {
            $confirm === null => null,
            $nacked => $confirm->fail(new PublishNacked("The broker has nacked the message published as {$tag}.")),
            $returned !== null => $confirm->fail($returned),
            default => $confirm->complete(),
        };
    }

    private function forget(int $tag): void
    {
        $returnId = $this->returnIds[$tag] ?? null;

        if ($returnId !== null) {
            unset($this->returnTags[$returnId]);
        }

        unset($this->unconfirmed[$tag], $this->returnIds[$tag], $this->returned[$tag]);
    }
}
