<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Http1\ChunkedReader;
use Thesis\Io\Buffer;
use Thesis\Io\ReadFailed;
use Thesis\Io\StringReader;

#[Test]
#[Covers(Form::class)]
final class FormTest
{
    public function encodesRepeatedFieldsAndLiteralPlusSigns(): void
    {
        $request = Form::urlEncoded('https://example.com', ['a b' => ['+', '']]);
        Assert::same($request->headers->get('content-type'), 'application/x-www-form-urlencoded');
        Assert::same($request->takeBody()->read(100), 'a+b=%2B&a+b=');
    }

    public function emitsTheDeclaredNumberOfBytesAcrossPartBoundaries(): void
    {
        $request = Form::multipart('https://example.com', [
            new FormPart('plain', 'value'),
            new FormPart('file', new StringReader('data'), filename: 'a"b.txt'),
        ]);
        $type = $request->headers->get('content-type');
        \assert($type !== null);
        $boundary = substr($type, \strlen('multipart/form-data; boundary='));
        $expected = "--{$boundary}\r\nContent-Disposition: form-data; name=\"plain\"\r\n\r\nvalue\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a%22b.txt\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\ndata\r\n--{$boundary}--\r\n";
        $body = $request->takeBody();
        Assert::same($body->size, \strlen($expected));
        $actual = '';

        while (($chunk = $body->read(2)) !== null) {
            Assert::true(\strlen($chunk) <= 2);
            $actual .= $chunk;
        }
        Assert::same($actual, $expected);
        Assert::true($body->trailers()->isEmpty);
    }

    public function forwardsAnUnknownLengthStream(): void
    {
        $source = new ChunkedReader(new Buffer(), new StringReader("4\r\ndata\r\n0\r\n\r\n"));
        $request = Form::multipart('https://example.com', [new FormPart('file', $source)]);
        $body = $request->takeBody();
        Assert::null($body->size);
        Assert::false($body->isBuffered);
        $body->read(8192);
        Assert::same($body->read(8192), 'data');

        while ($body->read(8192) !== null);

        Assert::true($source->isComplete);
    }

    public function rejectsAShortUpload(): void
    {
        $request = Form::multipart('https://example.com', [new FormPart('file', new StringReader('a'), size: 2)]);
        Expect::exception(ReadFailed::class);
        $request->takeBody()->readAll();
    }
}
