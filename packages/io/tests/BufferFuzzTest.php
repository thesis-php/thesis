<?php

declare(strict_types=1);

namespace Thesis\Io;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Differential test: applies the same random sequence of operations to {@see Buffer} and {@see ReferenceBuffer}
 * and requires identical results, exceptions, unread byte counts and contents.
 *
 * To reproduce a failure, run the reported seed alone.
 */
#[Test]
#[Covers(Buffer::class)]
final class BufferFuzzTest
{
    private const int SEQUENCES_PER_SEED = 200;
    private const array DELIMITERS = ["\r\n", "\n", "\x00", 'ab', "\x80"];

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
    public function behavesAsReferenceImplementation(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));

        for ($sequence = 0; $sequence < self::SEQUENCES_PER_SEED; ++$sequence) {
            $this->runSequence($random, "seed {$seed}, sequence {$sequence}");
        }
    }

    private function runSequence(Randomizer $random, string $context): void
    {
        $order = $random->getInt(0, 1) === 0 ? Order::Big : Order::Little;
        $initial = self::bytes($random, 0, 40);
        $chunkSize = $random->getInt(1, 64);
        $source = self::bytes($random, 0, 600);
        $readerSeed = $random->getInt(0, PHP_INT_MAX);

        $buffer = new Buffer($order, $initial, $chunkSize);
        $reference = new ReferenceBuffer($order, $initial, $chunkSize);
        $reader = new FuzzReader($source, $readerSeed);
        $referenceReader = new FuzzReader($source, $readerSeed);
        $writer = new RecordingWriter();
        $referenceWriter = new RecordingWriter();
        /** @var list<array{int, int}> $marks pairs of marks of the buffer and of the reference */
        $marks = [];
        /** @var list<array{Buffer, ReferenceBuffer}> $slices */
        $slices = [];
        $steps = $random->getInt(1, 60);

        for ($step = 0; $step < $steps; ++$step) {
            $op = self::randomOperation($random, $marks);
            $where = "{$context}, step {$step}, {$op['name']}";

            $result = null;
            $referenceResult = null;
            $outcome = self::outcome(static function () use ($op, $buffer, $reader, $writer, &$result): mixed {
                return $result = $op['run']($buffer, $reader, $writer);
            }, $op['name'] === 'reserve');
            $referenceOutcome = self::outcome(static function () use ($op, $reference, $referenceReader, $referenceWriter, &$referenceResult): mixed {
                return $referenceResult = $op['run']($reference, $referenceReader, $referenceWriter);
            }, $op['name'] === 'reserve');

            if ($outcome !== $referenceOutcome) {
                Assert::same($outcome, $referenceOutcome, $where);
            }

            if ($buffer->count() !== $reference->count()) {
                Assert::same($buffer->count(), $reference->count(), "{$where}: count");
            }

            if ($writer->writes !== $referenceWriter->writes) {
                Assert::same($writer->writes, $referenceWriter->writes, "{$where}: written bytes");
            }

            if ($op['name'] === 'reserve' && \is_int($result) && \is_int($referenceResult)) {
                $marks[] = [$result, $referenceResult];
            }

            if ($result instanceof Buffer && $referenceResult instanceof ReferenceBuffer) {
                $slices[] = [$result, $referenceResult];
            }

            if (\in_array($op['name'], ['drain', 'fill', 'fillUntil', 'flush'], true)) {
                // marks are invalidated by these operations
                $marks = [];
            }

            $unread = $buffer->peek($buffer->count());
            $referenceUnread = $reference->peek($reference->count());

            if ($unread !== $referenceUnread) {
                Assert::same(bin2hex($unread), bin2hex($referenceUnread), "{$where}: contents");
            }
        }

        // slices must be independent buffers of the same order
        foreach ($slices as [$slice, $referenceSlice]) {
            $slice->writeUint16(0x01_02)->writeUvarint(300);
            $referenceSlice->writeUint16(0x01_02)->writeUvarint(300);
            Assert::same(bin2hex($slice->drain()), bin2hex($referenceSlice->drain()), "{$context}: slice");
        }

        Assert::same(bin2hex($buffer->drain()), bin2hex($reference->drain()), "{$context}: final contents");
    }

    /**
     * @param list<array{int, int}> $marks
     * @return array{name: string, run: \Closure(Buffer|ReferenceBuffer, Reader, Writer): mixed}
     */
    private static function randomOperation(Randomizer $random, array $marks): array
    {
        $int = self::int($random);
        $n = $random->getInt(0, 12);
        $max = $random->getInt(1, 80);
        $delimiter = self::DELIMITERS[$random->getInt(0, \count(self::DELIMITERS) - 1)];
        $bytes = self::bytes($random, 0, 20);
        $float = self::float($random);

        if ($marks !== [] && $random->getInt(0, 4) === 0) {
            [$mark, $referenceMark] = $marks[$random->getInt(0, \count($marks) - 1)] ?? throw new \LogicException();

            if ($random->getInt(0, 2) === 0) {
                return [
                    'name' => 'sizeSince',
                    'run' => static fn(Buffer|ReferenceBuffer $x): int => $x->sizeSince($x instanceof Buffer ? $mark : $referenceMark),
                ];
            }

            $width = [1, 2, 4, 8][$random->getInt(0, 3)];
            $value = $width === 8 ? $int & PHP_INT_MAX : $int & ((1 << ($width * 8)) - 1);

            return [
                'name' => 'patchUint' . ($width * 8),
                'run' => static function (Buffer|ReferenceBuffer $x) use ($mark, $referenceMark, $width, $value): mixed {
                    $m = $x instanceof Buffer ? $mark : $referenceMark;

                    // patching beyond the written bytes is a contract violation checked by assert()
                    if ($x->sizeSince($m) >= $width) {
                        match ($width) {
                            1 => $x->patchUint8($m, $value),
                            2 => $x->patchUint16($m, $value),
                            4 => $x->patchUint32($m, $value),
                            8 => $x->patchUint64($m, $value),
                        };
                    }

                    return null;
                },
            ];
        }

        /** @var array<string, \Closure(Buffer|ReferenceBuffer, Reader, Writer): mixed> $ops */
        $ops = [
            'read' => static fn(Buffer|ReferenceBuffer $x): string => $x->read($n),
            'skip' => static fn(Buffer|ReferenceBuffer $x) => $x->skip($n),
            'peek' => static fn(Buffer|ReferenceBuffer $x): string => $x->peek($n, $max % 5),
            'slice' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->slice($n),
            'readUntil' => static fn(Buffer|ReferenceBuffer $x): ?string => $x->readUntil($delimiter),
            'readInt8' => static fn(Buffer|ReferenceBuffer $x): int => $x->readInt8(),
            'readUint8' => static fn(Buffer|ReferenceBuffer $x): int => $x->readUint8(),
            'readInt16' => static fn(Buffer|ReferenceBuffer $x): int => $x->readInt16(),
            'readUint16' => static fn(Buffer|ReferenceBuffer $x): int => $x->readUint16(),
            'readInt32' => static fn(Buffer|ReferenceBuffer $x): int => $x->readInt32(),
            'readUint32' => static fn(Buffer|ReferenceBuffer $x): int => $x->readUint32(),
            'readInt64' => static fn(Buffer|ReferenceBuffer $x): int => $x->readInt64(),
            'readUint64' => static fn(Buffer|ReferenceBuffer $x): int => $x->readUint64(),
            'readFloat' => static fn(Buffer|ReferenceBuffer $x): float => $x->readFloat(),
            'readDouble' => static fn(Buffer|ReferenceBuffer $x): float => $x->readDouble(),
            'readUvarint' => static fn(Buffer|ReferenceBuffer $x): int => $x->readUvarint(),
            'readVarint' => static fn(Buffer|ReferenceBuffer $x): int => $x->readVarint(),
            'write' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->write($bytes),
            'writeInt8' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeInt8(($int & 0xFF) - 0x80),
            'writeUint8' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeUint8($int & 0xFF),
            'writeInt16' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeInt16(($int & 0xFF_FF) - 0x80_00),
            'writeUint16' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeUint16($int & 0xFF_FF),
            'writeInt32' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeInt32(($int & 0xFF_FF_FF_FF) - 0x80_00_00_00),
            'writeUint32' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeUint32($int & 0xFF_FF_FF_FF),
            'writeInt64' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeInt64($int),
            'writeUint64' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeUint64($int & PHP_INT_MAX),
            'writeFloat' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeFloat($float),
            'writeDouble' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeDouble($float),
            'writeUvarint' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeUvarint($int),
            'writeVarint' => static fn(Buffer|ReferenceBuffer $x): Buffer|ReferenceBuffer => $x->writeVarint($int),
            'reserve' => static fn(Buffer|ReferenceBuffer $x): int => $x->reserve($n % 10),
            'drain' => static fn(Buffer|ReferenceBuffer $x): string => $x->drain(),
            'fill' => static fn(Buffer|ReferenceBuffer $x, Reader $r) => $x->fill($r, $n * 7),
            'fillUntil' => static fn(Buffer|ReferenceBuffer $x, Reader $r) => $x->fillUntil($r, $delimiter, $max),
            'flush' => static fn(Buffer|ReferenceBuffer $x, Reader $r, Writer $w) => $x->flush($w),
        ];

        $names = array_keys($ops);
        $name = $names[$random->getInt(0, \count($names) - 1)] ?? throw new \LogicException();

        return ['name' => $name, 'run' => $ops[$name] ?? throw new \LogicException()];
    }

    /**
     * Serialized result or exception, so that floats like NAN and -0.0 compare exactly.
     *
     * @param \Closure(): mixed $operation
     * @param bool $ignoreResult marks are implementation details, they are compared via sizeSince() and patches
     */
    private static function outcome(\Closure $operation, bool $ignoreResult): string
    {
        try {
            $result = $operation();

            if ($result instanceof Buffer || $result instanceof ReferenceBuffer) {
                // fluent writes return the buffer itself, slices a new one
                $result = ['buffer', $result->order, $result->count()];
            }

            return serialize(['ok', $ignoreResult ? null : $result]);
        } catch (\Throwable $exception) {
            return serialize([$exception::class, $exception->getMessage()]);
        }
    }

    /**
     * Random bytes biased towards varint continuation bytes and delimiters,
     * with occasional 8..11-byte varints to hit the 64-bit overflow boundary.
     */
    private static function bytes(Randomizer $random, int $min, int $max): string
    {
        $bytes = '';

        for ($i = $random->getInt($min, $max); $i > 0; --$i) {
            $bytes .= match ($random->getInt(0, 9)) {
                0, 1, 2 => \chr($random->getInt(0x80, 0xFF)),
                3 => "\r",
                4 => "\n",
                5 => "\x00",
                6 => $random->getInt(0, 4) === 0
                    ? str_repeat(\chr($random->getInt(0x80, 0xFF)), $random->getInt(7, 10)) . \chr($random->getInt(0, 3))
                    : \chr($random->getInt(0, 0x7F)),
                default => \chr($random->getInt(0, 0x7F)),
            };
        }

        return $bytes;
    }

    /**
     * Random int with a uniformly distributed bit length, a third of them negative.
     */
    private static function int(Randomizer $random): int
    {
        $v = $random->getInt(0, PHP_INT_MAX) >> $random->getInt(0, 63);

        return $random->getInt(0, 2) === 0 ? ~$v : $v;
    }

    private static function float(Randomizer $random): float
    {
        return match ($random->getInt(0, 9)) {
            0 => 0.0,
            1 => -0.0,
            2 => INF,
            3 => -INF,
            4 => NAN,
            5 => PHP_FLOAT_EPSILON,
            default => $random->getFloat(-1e6, 1e6),
        };
    }
}
