<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Random\Randomizer;
use Thesis\Amqp\Internal\ContentHeader;
use Thesis\Io\Buffer;

/**
 * Random values of the protocol from a seeded generator: every method, content headers, field tables.
 * Half of the numbers and the strings are at a bound (0, the maximum, the edges of 32 bits), where codecs break.
 */
final readonly class RandomProtocol
{
    private const int MAX_DEPTH = 3;

    public function __construct(
        private Randomizer $random,
    ) {}

    /**
     * @return non-empty-list<class-string<Method>>
     */
    public static function methodClasses(): array
    {
        $files = glob(\dirname(__DIR__, 3) . '/src/Internal/Protocol/*.php');
        $classes = [];

        foreach ($files === false ? [] : $files as $file) {
            $class = __NAMESPACE__ . '\\' . basename($file, '.php');

            if (is_subclass_of($class, Method::class)) {
                $classes[] = $class;
            }
        }

        \assert($classes !== []);

        return $classes;
    }

    /**
     * @template T of Method
     * @param class-string<T> $class
     * @return T
     */
    public function method(string $class): Method
    {
        return $this->instance(new \ReflectionClass($class));
    }

    public function anyMethod(): Method
    {
        return $this->method($this->pick(self::methodClasses()));
    }

    public function contentHeader(): ContentHeader
    {
        return new ContentHeader(
            bodySize: $this->int(PHP_INT_MAX),
            properties: $this->instance(new \ReflectionClass(BasicProperties::class)),
        );
    }

    /**
     * The payload of a header frame, as Frames::content() writes it.
     */
    public static function encodeContentHeader(ContentHeader $header): string
    {
        $buffer = new Buffer()
            ->writeUint16(BasicPublish::CLASS_ID)
            ->writeUint16(0)
            ->writeUint64($header->bodySize)
        ;
        $header->properties->encode($buffer);

        return $buffer->drain();
    }

    /**
     * @return array<string, mixed>
     */
    public function table(int $depth = 0): array
    {
        $table = [];
        $size = $this->random->getInt(0, $depth < self::MAX_DEPTH ? 5 : 0);

        for ($i = 0; $i < $size; ++$i) {
            $table[$this->shortString()] = $this->fieldValue($depth + 1);
        }

        /** @var array<string, mixed> PHP has made numeric keys ints, they are written as strings */
        return $table;
    }

    /**
     * @param int<0, max> $max
     * @return int<0, max>
     */
    public function int(int $max): int
    {
        $bounds = [0, $max, 2_147_483_647, 2_147_483_648, 4_294_967_295];

        return $this->bounded(
            bounds: array_filter($bounds, static fn($bound) => $bound <= $max),
            random: fn() => $this->random->getInt(0, $max),
        );
    }

    public function shortString(): string
    {
        return $this->bytes($this->bounded([0, 255], fn() => $this->random->getInt(1, 254)));
    }

    /**
     * @param non-negative-int $length
     */
    public function bytes(int $length): string
    {
        return $length === 0 ? '' : $this->random->getBytes($length);
    }

    /**
     * @template T of object
     * @param \ReflectionClass<T> $class
     * @return T
     */
    private function instance(\ReflectionClass $class): object
    {
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return $class->newInstance();
        }

        $doc = (string) $constructor->getDocComment();
        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            \assert($type instanceof \ReflectionNamedType);
            $name = $parameter->getName();

            $arguments[$name] = $type->allowsNull() && $this->random->getInt(0, 1) === 0
                ? null
                : match ($type->getName()) {
                    'bool' => $this->random->getInt(0, 1) === 1,
                    'string' => $this->shortString(),
                    'array' => $this->table(),
                    'int' => $this->int(self::max($doc, $name)),
                    default => throw new \LogicException("Unexpected type {$type} of \${$name}."),
                };
        }

        return $class->newInstanceArgs($arguments);
    }

    /**
     * @return int<0, max>
     */
    private static function max(string $doc, string $name): int
    {
        if (preg_match('/@param \??int<0, (\d+)> \$' . $name . '\b/', $doc, $match) === 1) {
            return max(0, (int) ($match[1] ?? 0));
        }

        return PHP_INT_MAX;
    }

    /**
     * What Fields::writeTable() can write: a NaN is left out, it is not equal to itself.
     */
    private function fieldValue(int $depth): mixed
    {
        return match ($this->random->getInt(0, 8)) {
            0 => $this->random->getInt(0, 1) === 1,
            1 => $this->bounded(
                bounds: [PHP_INT_MIN, PHP_INT_MAX, -2_147_483_649, -2_147_483_648, 2_147_483_647, 2_147_483_648, 0],
                random: fn() => $this->random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            ),
            2 => $this->bounded(
                bounds: [0.0, -0.0, INF, -INF, PHP_FLOAT_MIN, PHP_FLOAT_MAX, PHP_FLOAT_EPSILON],
                random: fn() => $this->random->getFloat(-1e300, 1e300),
            ),
            3 => $this->bytes($this->bounded([0, 256], fn() => $this->random->getInt(1, 300))),
            4 => null,
            5 => new \DateTimeImmutable('@' . $this->int(PHP_INT_MAX)),
            6 => $this->list($depth),
            7, 8 => $this->table($depth),
        };
    }

    /**
     * @return list<mixed>
     */
    private function list(int $depth): array
    {
        $list = [];
        $size = $this->random->getInt(1, $depth < self::MAX_DEPTH ? 5 : 1);

        for ($i = 0; $i < $size; ++$i) {
            $list[] = $depth < self::MAX_DEPTH ? $this->fieldValue($depth + 1) : $this->shortString();
        }

        return $list;
    }

    /**
     * @template T
     * @param array<T> $bounds
     * @param callable(): T $random
     * @return T
     */
    private function bounded(array $bounds, callable $random): mixed
    {
        if ($bounds !== [] && $this->random->getInt(0, 1) === 0) {
            return $this->pick($bounds);
        }

        return $random();
    }

    /**
     * @template T
     * @param non-empty-array<T> $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        $key = $this->random->pickArrayKeys($values, 1)[0];
        \assert(\array_key_exists($key, $values));

        return $values[$key];
    }
}
