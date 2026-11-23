<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Headers::class)]
final class HeadersTest
{
    public function isEmptyByDefault(): void
    {
        $headers = new Headers();

        Assert::true($headers->isEmpty);
        Assert::same(lines($headers), []);
        Assert::false($headers->has('accept'));
        Assert::null($headers->get('accept'));
        Assert::same($headers->all('accept'), []);
    }

    public function findsFieldByNameInAnyCase(): void
    {
        $headers = new Headers(['Content-Type' => 'text/html']);

        Assert::true($headers->has('content-type'));
        Assert::true($headers->has('CONTENT-TYPE'));
        Assert::same($headers->get('cONTENT-tYPE'), 'text/html');
        Assert::same($headers->all('Content-Type'), ['text/html']);
    }

    public function keepsNamesInLowercaseAndInOrderOfAddition(): void
    {
        $headers = new Headers(['X-B' => 'b', 'Accept' => ['text/html', 'text/plain'], 'x-a' => 'a']);

        Assert::same(lines($headers), [
            ['x-b', 'b'],
            ['accept', 'text/html'],
            ['accept', 'text/plain'],
            ['x-a', 'a'],
        ]);
    }

    public function combinesLinesOfOneFieldByComma(): void
    {
        $headers = new Headers(['accept' => ['text/html', 'text/plain;q=0.5']]);

        Assert::same($headers->get('accept'), 'text/html, text/plain;q=0.5');
        Assert::same($headers->all('accept'), ['text/html', 'text/plain;q=0.5']);
    }

    public function addsLinesOfNameGivenTwiceInDifferentCase(): void
    {
        $headers = new Headers((static function () {
            yield 'Accept' => 'text/html';
            yield 'x-other' => 'other';
            yield 'accept' => ['text/plain', 'text/css'];
        })());

        Assert::same(lines($headers), [
            ['accept', 'text/html'],
            ['accept', 'text/plain'],
            ['accept', 'text/css'],
            ['x-other', 'other'],
        ]);
    }

    public function hasNoFieldOfEmptyList(): void
    {
        $headers = new Headers(['accept' => []]);

        Assert::false($headers->has('accept'));
        Assert::same(lines($headers), []);
    }

    public function keepsNameThatIsNumberAsString(): void
    {
        $headers = new Headers((static function () {
            yield '1' => 'one';
        })())->withAdded('02', 'two')->with('3', 'three');

        Assert::same(lines($headers), [['1', 'one'], ['02', 'two'], ['3', 'three']]);
        Assert::same($headers->get('1'), 'one');
        Assert::same(lines($headers->without('1', '3')), [['02', 'two']]);
    }

    public function dropsSpacesAndTabsAroundValue(): void
    {
        $headers = new Headers(['a' => " \t one two\t \t"])
            ->with('b', "\ttwo ")
            ->withAdded('c', [' three', "four\t"])
        ;

        Assert::same(lines($headers), [['a', 'one two'], ['b', 'two'], ['c', 'three'], ['c', 'four']]);
    }

    public function keepsTabsInsideValueAndBytesAboveAscii(): void
    {
        $headers = new Headers(['x' => "a\tb \xC3\xA9\xFF", 'empty' => '']);

        Assert::same($headers->get('x'), "a\tb \xC3\xA9\xFF");
        Assert::same($headers->get('empty'), '');
        Assert::true($headers->has('empty'));
    }

    public function replacesLinesInPlaceOfOldOnes(): void
    {
        $headers = new Headers(['a' => ['1', '2'], 'b' => '3']);

        $replaced = $headers->with('A', ['4', '5']);

        Assert::same(lines($replaced), [['a', '4'], ['a', '5'], ['b', '3']]);
        Assert::same(lines($headers), [['a', '1'], ['a', '2'], ['b', '3']]);
    }

    public function addsNewFieldAtEnd(): void
    {
        $headers = new Headers(['a' => '1']);

        Assert::same(lines($headers->with('B', '2')), [['a', '1'], ['b', '2']]);
        Assert::same(lines($headers->withAdded('B', '2')), [['a', '1'], ['b', '2']]);
    }

    public function removesFieldReplacedByEmptyList(): void
    {
        $headers = new Headers(['a' => '1', 'b' => '2']);

        Assert::same(lines($headers->with('A', [])), [['b', '2']]);
    }

    public function addsLinesToThoseFieldHas(): void
    {
        $headers = new Headers(['a' => '1', 'b' => '2']);

        $added = $headers->withAdded('A', ['3', '4'])->withAdded('a', '5');

        Assert::same(lines($added), [['a', '1'], ['a', '3'], ['a', '4'], ['a', '5'], ['b', '2']]);
        Assert::same(lines($headers), [['a', '1'], ['b', '2']]);
    }

    public function addsNoFieldOfEmptyList(): void
    {
        $headers = new Headers(['a' => '1'])->withAdded('b', []);

        Assert::false($headers->has('b'));
    }

    public function removesFieldsByNameInAnyCase(): void
    {
        $headers = new Headers(['a' => '1', 'b' => '2', 'c' => '3']);

        $removed = $headers->without('C', 'a', 'missing');

        Assert::same(lines($removed), [['b', '2']]);
        Assert::false($removed->isEmpty);
        Assert::true($removed->without('b')->isEmpty);
        Assert::same(lines($headers), [['a', '1'], ['b', '2'], ['c', '3']]);
    }

    #[DataProvider('invalidNames')]
    public function rejectsNameThatIsNotToken(string $name, string $quoted): void
    {
        $message = "A header name must be a token, got {$quoted}.";

        Assert::same(failure(static fn() => new Headers([$name => 'value'])), $message);
        Assert::same(failure(static fn() => new Headers()->with($name, 'value')), $message);
        Assert::same(failure(static fn() => new Headers()->withAdded($name, 'value')), $message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => ['', '""'];
        yield 'space' => ['a b', '"a b"'];
        yield 'colon' => ['a:b', '"a:b"'];
        yield 'new line' => ["a\r\nb", '"a\r\nb"'];
        yield 'quote' => ['a"b', '"a\"b"'];
        yield 'above ASCII' => ["caf\xC3\xA9", '"caf\303\251"'];
        yield 'leading space' => [' a', '" a"'];
    }

    #[DataProvider('invalidValues')]
    public function rejectsValueWithControlCharacter(string $value, string $quoted): void
    {
        $message = "A value of the header \"x-name\" must have no control characters, got {$quoted}.";

        Assert::same(failure(static fn() => new Headers(['X-Name' => $value])), $message);
        Assert::same(failure(static fn() => new Headers(['X-Name' => ['valid', $value]])), $message);
        Assert::same(failure(static fn() => new Headers()->with('X-Name', $value)), $message);
        Assert::same(failure(static fn() => new Headers()->withAdded('X-Name', $value)), $message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'CR' => ["a\rb", '"a\rb"'];
        yield 'LF' => ["a\nb", '"a\nb"'];
        yield 'injected line' => ["a\r\nSet-Cookie: x=1", '"a\r\nSet-Cookie: x=1"'];
        yield 'NUL' => ["a\0b", '"a\000b"'];
        yield 'first control' => ["\x01", '"\001"'];
        yield 'last control before tab' => ["\x08", '"\b"'];
        yield 'last control' => ["\x1F", '"\037"'];
        yield 'DEL' => ["a\x7F", '"a\177"'];
        yield 'trailing LF' => ["a\n", '"a\n"'];
    }

    public function allowsEveryCharacterOfToken(): void
    {
        $name = '!#$%&\'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyz';

        Assert::same(new Headers([strtoupper($name) => 'value'])->get($name), 'value');
    }

    public function rejectsEveryByteThatIsNotOfToken(): void
    {
        $token = '!#$%&\'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        for ($byte = 0; $byte < 256; ++$byte) {
            $name = 'a' . \chr($byte);

            Assert::same(
                actual: failure(static fn() => new Headers([$name => 'value'])) === null,
                expected: str_contains($token, \chr($byte)),
                message: "byte {$byte}",
            );
        }
    }

    public function allowsEveryByteOfValueButControlCharacters(): void
    {
        for ($byte = 0; $byte < 256; ++$byte) {
            $value = 'a' . \chr($byte) . 'b';

            Assert::same(
                actual: failure(static fn() => new Headers(['x' => $value])) === null,
                expected: $byte === 0x09 || ($byte >= 0x20 && $byte !== 0x7F),
                message: "byte {$byte}",
            );
        }
    }
}
