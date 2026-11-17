<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Testo\Assert;
use Testo\Test;
use Thesis\Io\Buffer;

#[Test]
final class MethodTest
{
    public function everyMethodDecodesAsEncoded(): void
    {
        $classes = glob(\dirname(__DIR__, 3) . '/src/Internal/Protocol/*.php');
        $classes = $classes === false ? [] : $classes;
        $tested = 0;

        foreach ($classes as $file) {
            $class = __NAMESPACE__ . '\\' . basename($file, '.php');

            if (!is_subclass_of($class, Method::class)) {
                continue;
            }

            for ($i = 0; $i < 20; ++$i) {
                $method = self::random(new \ReflectionClass($class));
                $buffer = new Buffer();
                $method->encode($buffer);

                Assert::equals(Methods::decode($buffer), $method);
                Assert::same($buffer->count(), 0);
            }

            ++$tested;
        }

        Assert::same($tested, 67 - 3);
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

    /**
     * @template T of Method
     * @param \ReflectionClass<T> $class
     * @return T
     */
    private static function random(\ReflectionClass $class): Method
    {
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return $class->newInstance();
        }

        $doc = (string) $constructor->getDocComment();
        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = (string) $parameter->getType();

            $arguments[$name] = match ($type) {
                'bool' => random_int(0, 1) === 1,
                'string' => str_repeat('x', random_int(0, 3)) . random_bytes(random_int(1, 252)),
                'array' => [
                    'key' => random_int(PHP_INT_MIN, PHP_INT_MAX),
                    'nested' => ['a' => true, 'list' => [1, 'x']],
                ],
                'int' => random_int(0, self::max($doc, $name)),
                default => throw new \LogicException("Unexpected type {$type} of \${$name}."),
            };
        }

        return $class->newInstanceArgs($arguments);
    }

    private static function max(string $doc, string $name): int
    {
        if (preg_match('/@param int<0, (\d+)> \$' . $name . '\b/', $doc, $match) === 1) {
            return (int) ($match[1] ?? 0);
        }

        return PHP_INT_MAX;
    }
}
