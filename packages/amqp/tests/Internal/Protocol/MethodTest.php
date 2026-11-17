<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;
use Thesis\Amqp\Internal\ContentHeader;
use Thesis\Io\Buffer;

#[Test]
final class MethodTest
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function everyMethodDecodesAsEncoded(int $seed): void
    {
        $random = new RandomProtocol(new Randomizer(new Mt19937($seed)));
        $classes = RandomProtocol::methodClasses();

        foreach ($classes as $class) {
            for ($i = 0; $i < 20; ++$i) {
                $method = $random->method($class);
                $buffer = new Buffer();
                $method->encode($buffer);

                Assert::equals(Methods::decode($buffer), $method);
                Assert::same($buffer->count(), 0);
            }
        }

        Assert::same(\count($classes), 67 - 3);
    }

    #[DataProvider('seeds')]
    public function contentHeaderDecodesAsEncoded(int $seed): void
    {
        $random = new RandomProtocol(new Randomizer(new Mt19937($seed)));

        for ($i = 0; $i < 100; ++$i) {
            $header = $random->contentHeader();
            $buffer = new Buffer()->write(RandomProtocol::encodeContentHeader($header));

            Assert::equals(ContentHeader::decode($buffer), $header);
            Assert::same($buffer->count(), 0);
        }
    }

    public function packsConsecutiveBitsIntoOneOctet(): void
    {
        $buffer = new Buffer();

        new QueueDeclare(
            queue: 'q',
            passive: true,
            durable: false,
            exclusive: true,
            autoDelete: false,
            noWait: true,
            arguments: [],
        )->encode($buffer);

        Assert::same(bin2hex($buffer->drain()), '0032000a000001711500000000');
    }
}
