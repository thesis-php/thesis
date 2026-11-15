<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

use Thesis\Io\Buffer;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\DnsFailed;

/**
 * Encodes a query and decodes the addresses of a response (RFC 1035).
 *
 * @internal
 */
final readonly class Message
{
    public const int A = 1;
    public const int AAAA = 28;
    public const int NOERROR = 0;
    public const int NXDOMAIN = 3;
    private const int CLASS_IN = 1;
    private const int FLAG_RESPONSE = 0x8000;
    private const int FLAG_TRUNCATED = 0x0200;
    private const int FLAG_RECURSION_DESIRED = 0x0100;

    /**
     * @param int<0, 65535> $id
     * @param non-empty-string $name without the trailing dot
     * @param self::A|self::AAAA $type
     * @throws DnsFailed if $name is not a valid host name
     */
    public static function query(int $id, string $name, int $type): string
    {
        if (\strlen($name) > 253) {
            throw new DnsFailed(\sprintf('"%s" is not a valid host name: too long.', $name));
        }

        $buffer = new Buffer()
            ->writeUint16($id)
            ->writeUint16(self::FLAG_RECURSION_DESIRED)
            ->writeUint16(1)
            ->writeUint16(0)
            ->writeUint16(0)
            ->writeUint16(0)
        ;

        foreach (explode('.', $name) as $label) {
            $length = \strlen($label);

            if ($length === 0 || $length > 63) {
                throw new DnsFailed(\sprintf('"%s" is not a valid host name.', $name));
            }

            $buffer->writeUint8($length)->write($label);
        }

        return $buffer
            ->writeUint8(0)
            ->writeUint16($type)
            ->writeUint16(self::CLASS_IN)
            ->drain()
        ;
    }

    /**
     * @param string $query as {@see query()} has encoded it
     * @return ?Response null if $bytes answer another query: another id or another question
     * @throws DnsFailed if the response is malformed
     */
    public static function parse(string $bytes, string $query): ?Response
    {
        try {
            return self::decode(new Buffer(bytes: $bytes), $query);
        } catch (UnexpectedEof) {
            throw new DnsFailed('Malformed response.');
        }
    }

    /**
     * @throws UnexpectedEof
     * @throws DnsFailed
     */
    private static function decode(Buffer $buffer, string $query): ?Response
    {
        if ($buffer->read(2) !== substr($query, 0, 2)) {
            return null;
        }

        $flags = $buffer->readUint16();
        $questions = $buffer->readUint16();
        $answers = $buffer->readUint16();
        $buffer->skip(4);

        if (($flags & self::FLAG_RESPONSE) === 0) {
            throw new DnsFailed('Malformed response: a query.');
        }

        $truncated = ($flags & self::FLAG_TRUNCATED) !== 0;
        $rcode = $flags & 0x0F;

        // A server may drop the question from an error.
        if ($questions === 0) {
            return new Response(truncated: $truncated, rcode: $rcode, addresses: [], ttl: 0);
        }

        if ($questions !== 1) {
            throw new DnsFailed(\sprintf('Malformed response: %d questions.', $questions));
        }

        // The question of a response is never compressed: a pointer can only point back, and nothing is before it.
        // Case-insensitive, since a server may randomize the case of the name it asks upstream (DNS 0x20).
        $question = substr($query, 12);

        if (strcasecmp($buffer->read(\strlen($question)), $question) !== 0) {
            return null;
        }

        if ($truncated) {
            return new Response(truncated: true, rcode: $rcode, addresses: [], ttl: 0);
        }

        $type = new Buffer(bytes: substr($question, -4, 2))->readUint16();
        $size = $type === self::AAAA ? 16 : 4;
        $addresses = [];
        $ttl = null;

        for ($i = 0; $i < $answers; ++$i) {
            self::skipName($buffer);
            $recordType = $buffer->readUint16();
            $class = $buffer->readUint16();
            $recordTtl = $buffer->readUint32();
            $length = $buffer->readUint16();

            if ($recordType !== $type || $class !== self::CLASS_IN || $length !== $size) {
                $buffer->skip($length);

                continue;
            }

            $address = inet_ntop($buffer->read($length));
            \assert(\is_string($address) && $address !== '');
            $addresses[] = $address;
            $ttl = min($ttl ?? $recordTtl, $recordTtl);
        }

        return new Response(truncated: false, rcode: $rcode, addresses: $addresses, ttl: $ttl ?? 0);
    }

    /**
     * The owner names are not needed: only the records of the type asked for are taken,
     * so a compression pointer ends a name without being followed.
     *
     * @throws UnexpectedEof
     * @throws DnsFailed
     */
    private static function skipName(Buffer $buffer): void
    {
        while (($length = $buffer->readUint8()) !== 0) {
            if (($length & 0xC0) === 0xC0) {
                $buffer->skip(1);

                return;
            }

            if ($length > 63) {
                throw new DnsFailed('Malformed response: a bad label.');
            }

            $buffer->skip($length);
        }
    }
}
