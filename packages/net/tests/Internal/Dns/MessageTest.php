<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Net\DnsFailed;

#[Test]
final class MessageTest
{
    public function encodesAQuery(): void
    {
        $query = Message::query(0xAB_CD, 'db.test', Message::AAAA);

        Assert::same(bin2hex($query), 'abcd01000001000000000000026462047465737400001c0001');
    }

    public function rejectsAnEmptyLabel(): void
    {
        Expect::exception(DnsFailed::class)->withMessage('"db..test" is not a valid host name.');

        Message::query(1, 'db..test', Message::A);
    }

    public function rejectsALongLabel(): void
    {
        Expect::exception(DnsFailed::class);

        Message::query(1, str_repeat('a', 64) . '.test', Message::A);
    }

    public function takesTheAddressesOfTheTypeAskedFor(): void
    {
        $response = Message::parse(
            bytes: self::bytes(
                'abcd8180000100030000000002646204746573740000010001'
                // CNAME to x.test, ttl 300
                . 'c00c000500010000012c00080178047465737400'
                // A 10.0.0.1, ttl 60
                . 'c00c000100010000003c00040a000001'
                // A 10.0.0.2, ttl 30
                . 'c00c000100010000001e00040a000002',
            ),
            query: Message::query(0xAB_CD, 'db.test', Message::A),
        );

        Assert::same($response?->rcode, Message::NOERROR);
        Assert::same($response->addresses, ['10.0.0.1', '10.0.0.2']);
        Assert::same($response->ttl, 30);
    }

    public function matchesTheQuestionWhateverTheCase(): void
    {
        $query = Message::query(1, 'db.test', Message::A);

        $response = Message::parse(self::bytes('00018180000100000000000002446204546573740000010001'), $query);

        Assert::notNull($response);
    }

    public function ignoresAnAnswerWithAnotherId(): void
    {
        $query = Message::query(2, 'test', Message::A);

        Assert::null(Message::parse(self::bytes('00018180000100000000000004746573740000010001'), $query));
    }

    public function ignoresAnAnswerToAnotherName(): void
    {
        $query = Message::query(1, 'best', Message::A);

        Assert::null(Message::parse(self::bytes('00018180000100000000000004746573740000010001'), $query));
    }

    public function ignoresAnAnswerToAnotherType(): void
    {
        $query = Message::query(1, 'test', Message::AAAA);

        Assert::null(Message::parse(self::bytes('00018180000100000000000004746573740000010001'), $query));
    }

    public function takesAnErrorWithoutTheQuestion(): void
    {
        $query = Message::query(1, 'test', Message::A);

        $response = Message::parse(self::bytes('000181820000000000000000'), $query);

        Assert::same($response?->rcode, 2);
        Assert::same($response->addresses, []);
    }

    public function stopsAtTheTruncatedFlag(): void
    {
        $query = Message::query(1, 'test', Message::A);

        $response = Message::parse(self::bytes('00018380000100000000000004746573740000010001'), $query);

        Assert::true($response?->truncated);
        Assert::same($response->addresses, []);
    }

    public function rejectsATruncatedMessage(): void
    {
        $query = Message::query(1, 'test', Message::A);
        Expect::exception(DnsFailed::class)->withMessage('Malformed response.');

        Message::parse(self::bytes('00018180000100010000000004746573740000010001c00c0001'), $query);
    }

    public function rejectsAQuery(): void
    {
        $query = Message::query(1, 'test', Message::A);
        Expect::exception(DnsFailed::class)->withMessage('Malformed response: a query.');

        Message::parse($query, $query);
    }

    private static function bytes(string $hex): string
    {
        $bytes = hex2bin($hex);
        \assert($bytes !== false);

        return $bytes;
    }
}
