<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Thesis\Amqp\Internal\Protocol\ProtocolError;
use Thesis\Amqp\Internal\Protocol\RandomProtocol;
use Thesis\Io\Buffer;

/**
 * Whatever bytes the server sends, a frame decodes or fails with ProtocolError: any other exception would escape
 * the reader instead of closing the connection. The corpus is valid frames, mutated.
 *
 * To reproduce a failure, run the reported seed alone.
 */
#[Test]
#[Covers(Frames::class)]
final class FramesFuzzTest
{
    private const int FRAMES_PER_SEED = 2000;

    /**
     * The bytes with one bit set, a mask per bit.
     */
    private const string BIT_MASKS = "\x01\x02\x04\x08\x10\x20\x40\x80";

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function decodesOrFailsWithProtocolError(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));
        $protocol = new RandomProtocol($random);
        $decoded = 0;
        $rejected = 0;

        for ($frame = 0; $frame < self::FRAMES_PER_SEED; ++$frame) {
            [$type, $payload] = self::validFrame($random, $protocol);
            $mutations = $random->getInt(1, 4);

            for ($i = 0; $i < $mutations; ++$i) {
                [$type, $payload] = self::mutate($random, $type, $payload);
            }

            try {
                Frames::decode($type, new Buffer()->write($payload));
                ++$decoded;
            } catch (ProtocolError) {
                ++$rejected;
            } catch (\Throwable $exception) {
                $hex = bin2hex($payload);
                $class = $exception::class;

                throw new \LogicException(
                    message: "Seed {$seed}, frame {$frame}: a frame of type {$type} with the payload {$hex}"
                        . " fails with {$class}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
        }

        // Both ways out are exercised: the mutations neither break every frame nor leave them all valid.
        Assert::true($decoded > 0);
        Assert::true($rejected > 0);
    }

    /**
     * @return array{int, string}
     */
    private static function validFrame(Randomizer $random, RandomProtocol $protocol): array
    {
        return match ($random->getInt(0, 3)) {
            0, 1 => [Frames::METHOD, self::encodeMethod($protocol)],
            2 => [Frames::HEADER, RandomProtocol::encodeContentHeader($protocol->contentHeader())],
            3 => [Frames::BODY, $protocol->bytes($random->getInt(0, 100))],
        };
    }

    private static function encodeMethod(RandomProtocol $protocol): string
    {
        $buffer = new Buffer();
        $protocol->anyMethod()->encode($buffer);

        return $buffer->drain();
    }

    /**
     * @return array{int, string}
     */
    private static function mutate(Randomizer $random, int $type, string $payload): array
    {
        $length = \strlen($payload);
        $offset = $random->getInt(0, $length);

        return match ($random->getInt(0, 7)) {
            // A bit flipped.
            0 => $length === 0 ? [$type, $payload] : [$type, self::flipBit($random, $payload)],
            // Cut short.
            1 => [$type, substr($payload, 0, $offset)],
            // Bytes after the fields.
            2 => [$type, $payload . $random->getBytes($random->getInt(1, 8))],
            // Bytes in the middle.
            3 => [$type, substr_replace($payload, $random->getBytes($random->getInt(1, 8)), $offset, 0)],
            // Bytes taken out of the middle.
            4 => [$type, substr_replace($payload, '', $offset, $random->getInt(1, 8))],
            // A length or a count of 32 bits changed, at the bounds where a reader runs past its end.
            5 => [$type, self::overwrite($payload, $offset, pack('N', self::uint32($random)))],
            // A long-long of 2^63 and above.
            6 => [$type, self::overwrite($payload, $offset, "\x80" . $random->getBytes(7))],
            // Another type of frame, a known one more often.
            7 => [$random->getInt(0, 1) === 0 ? $random->getInt(0, 255) : $random->getInt(1, 3), $payload],
        };
    }

    private static function flipBit(Randomizer $random, string $payload): string
    {
        $offset = $random->getInt(0, \strlen($payload) - 1);
        $flipped = $payload[$offset] ^ self::BIT_MASKS[$random->getInt(0, 7)];

        return substr_replace($payload, $flipped, $offset, 1);
    }

    private static function uint32(Randomizer $random): int
    {
        return match ($random->getInt(0, 4)) {
            0 => 0,
            1 => $random->getInt(1, 16),
            2 => 0x7F_FF_FF_FF,
            3 => 0xFF_FF_FF_FF,
            4 => $random->getInt(0, 0xFF_FF_FF_FF),
        };
    }

    /**
     * $bytes written over the payload at $offset, as far as the payload goes.
     */
    private static function overwrite(string $payload, int $offset, string $bytes): string
    {
        return substr_replace($payload, substr($bytes, 0, \strlen($payload) - $offset), $offset, \strlen($bytes));
    }
}
