<?php

declare(strict_types=1);

namespace Thesis\Formatter;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
final class FormatTest
{
    #[DataSet([null, 'null'])]
    #[DataSet([true, 'true'])]
    #[DataSet([false, 'false'])]
    #[DataSet([42, '42'])]
    #[DataSet([PHP_INT_MIN, '-9223372036854775808'])]
    #[DataSet([1.5, '1.5'])]
    #[DataSet([1.0, '1.0'], name: 'a float apart from an int')]
    #[DataSet([-0.0, '-0.0'])]
    #[DataSet([0.1 + 0.2, '0.30000000000000004'], name: 'a float in full')]
    #[DataSet([1e100, '1.0E+100'])]
    #[DataSet([INF, 'INF'])]
    #[DataSet([-INF, '-INF'])]
    #[DataSet([NAN, 'NAN'])]
    #[DataSet([Suit::Hearts, 'Thesis\Formatter\Suit::Hearts'])]
    #[DataSet([new Service(), Service::class])]
    public function formatsScalarsAndObjects(mixed $value, string $expected): void
    {
        Assert::same(format($value), $expected);
    }

    #[DataSet(['', "''"])]
    #[DataSet(['text', "'text'"])]
    #[DataSet(['привет', "'привет'"], name: 'UTF-8 as is')]
    #[DataSet(["it's", "'it\\'s'"])]
    #[DataSet(['Foo\Bar', "'Foo\\Bar'"], name: 'a backslash that PHP reads as is')]
    #[DataSet(['a\\', "'a\\\\'"], name: 'a backslash at the end')]
    #[DataSet(['a\\\b', "'a\\\\\\b'"], name: 'a backslash before a backslash')]
    #[DataSet(["a\\'", "'a\\\\\\''"], name: 'a backslash before a quote')]
    #[DataSet(["x\ny\0", '"x\ny\x00"'], name: 'control bytes')]
    #[DataSet(["\t\r\v\f\e\x7F", '"\t\r\v\f\e\x7F"'])]
    #[DataSet(["\n\"\$\\", '"\n\"\$\\\"'], name: 'what double quotes escape')]
    #[DataSet(["\x80\xFF", '"\x80\xFF"'], name: 'not UTF-8')]
    #[DataSet(["Приве\xD1", '"Приве\xD1"'], name: 'a cut UTF-8 character')]
    #[DataSet(["\xE0\x80\xED\xA0\x80", '"\xE0\x80\xED\xA0\x80"'], name: 'overlong and surrogate')]
    #[DataSet(['😀 €', "'😀 €'"])]
    #[DataSet(["\u{9B}31m", '"\u{9B}31m"'], name: 'a C1 control')]
    #[DataSet(["abc\u{202E}def", '"abc\u{202E}def"'], name: 'a bidi control')]
    #[DataSet(["\u{200B}\u{FEFF}\u{AD}", '"\u{200B}\u{FEFF}\u{AD}"'], name: 'invisible formats')]
    #[DataSet(["\u{A0}\u{3000}\u{2028}\u{2029}", '"\u{A0}\u{3000}\u{2028}\u{2029}"'], name: 'spaces and separators')]
    #[DataSet(["\u{E0001}", '"\u{E0001}"'], name: 'a format of 4 bytes')]
    public function formatsStrings(string $value, string $expected): void
    {
        Assert::same(format($value), $expected);
    }

    public function cutsBeforeByteThatIsNotUtf8(): void
    {
        $string = str_repeat('я', 60) . str_repeat('x', 1000) . "\xFF";

        Assert::same(format($string), "'" . str_repeat('я', 60) . str_repeat('x', 40) . "'...");
    }

    public function keepsStringOfMaxLength(): void
    {
        $string = str_repeat('a', 100);

        Assert::same(format($string), "'{$string}'");
    }

    public function cutsLongString(): void
    {
        Assert::same(format(str_repeat('a', 101)), "'" . str_repeat('a', 100) . "'...");
    }

    public function cutsLongUtf8StringByCharacters(): void
    {
        Assert::same(format(str_repeat('я', 101)), "'" . str_repeat('я', 100) . "'...");
    }

    public function cutsLongBinaryStringByBytes(): void
    {
        Assert::same(format(str_repeat("\xFF", 101)), '"' . str_repeat('\xFF', 100) . '"...');
    }

    public function cutsControlBytesOfLongString(): void
    {
        Assert::same(format(str_repeat("\n", 101)), '"' . str_repeat('\n', 100) . '"...');
    }

    /**
     * @param array<mixed> $value
     */
    #[DataSet([[], 'array{}'])]
    #[DataSet([[1, 'a'], "list{1, 'a'}"])]
    #[DataSet([['a' => 1, 'b' => [true]], 'array{a: 1, b: list{true}}'])]
    #[DataSet([[1 => 'x'], "array{1: 'x'}"])]
    #[DataSet([['_a1' => 1], 'array{_a1: 1}'])]
    #[DataSet([["it's" => 1], "array{'it\\'s': 1}"], name: 'a key with a quote')]
    #[DataSet([['a b' => 1, '1a' => 2, '' => 3], "array{'a b': 1, '1a': 2, '': 3}"], name: 'keys that are not names')]
    #[DataSet([["id\n" => 1], 'array{"id\n": 1}'], name: 'a key with a newline at the end')]
    #[DataSet([[[[[1]]]], 'list{list{list{list{...}}}}'], name: 'too deep')]
    #[DataSet([[[[[]]]], 'list{list{list{array{}}}}'], name: 'empty too deep')]
    public function formatsArrays(array $value, string $expected): void
    {
        Assert::same(format($value), $expected);
    }

    public function stopsItemsAtMaxLength(): void
    {
        $formatted = format(range(1, 10_000));

        Assert::string($formatted)->startsWith('list{1, 2, 3, ');
        Assert::string($formatted)->endsWith(', ...}');
        Assert::true(\strlen($formatted) <= 510);
    }

    public function stopsItemsOfNestedArraysAtMaxLengthOfAll(): void
    {
        $formatted = format([range(1, 10_000), range(1, 10_000)]);

        Assert::string($formatted)->startsWith('list{list{1, 2, 3, ');
        Assert::string($formatted)->endsWith(', ...}, ...}');
        Assert::true(\strlen($formatted) <= 515);
    }

    public function cutsStringToWhatIsLeftOfMaxLength(): void
    {
        $string = "'" . str_repeat('a', 100) . "'";
        $cut = "'" . str_repeat('a', 79) . "'...";

        Assert::same(
            actual: format(array_fill(0, 10, str_repeat('a', 100))),
            expected: "list{{$string}, {$string}, {$string}, {$string}, {$cut}, ...}",
        );
    }

    public function keepsLongKeyAndValueWithinMaxLength(): void
    {
        for ($count = 1; $count <= 100; ++$count) {
            $items = array_fill(0, $count, 1);
            $items[str_repeat("\x01", 150)] = str_repeat("\x01", 150);

            Assert::true(\strlen(format($items)) <= 515, message: "{$count} items before");
        }
    }

    public function endsCycleOfReferences(): void
    {
        $array = [];
        $array[] = &$array;

        Assert::same(format($array), 'list{list{list{list{...}}}}');
    }

    public function formatsStdClass(): void
    {
        Assert::same(format(new \stdClass()), 'object{}');
        Assert::same(format((object) ['x' => 1, 'y y' => 'a']), "object{x: 1, 'y y': 'a'}");
    }

    public function formatsSubclassOfStdClassByClass(): void
    {
        $object = new class extends \stdClass {
            public string $password = 'secret';
        };

        Assert::same(format($object), 'stdClass@' . __FILE__ . ':' . (__LINE__ - 4));
    }

    public function endsCycleOfObjects(): void
    {
        $object = new \stdClass();
        $object->self = $object;

        Assert::same(format($object), 'object{self: object{self: object{self: object{...}}}}');
    }

    public function formatsClosure(): void
    {
        Assert::same(format(static fn() => null), 'function@' . __FILE__ . ':' . __LINE__ . '()');
    }

    public function formatsAnonymousObject(): void
    {
        Assert::same(format(new class {}), 'class@' . __FILE__ . ':' . __LINE__);
    }

    public function formatsResources(): void
    {
        $stream = fopen('php://memory', 'r');
        \assert(\is_resource($stream));
        Assert::same(format($stream), 'resource (stream)');

        fclose($stream);
        Assert::same(format($stream), 'resource (closed)');
    }
}
