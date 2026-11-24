<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\HttpProtocol\Http1\ChunkedReader;
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
        Assert::same($request->body->read(100), 'a+b=%2B&a+b=');
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
        Assert::same($request->body->size, \strlen($expected));
        $actual = '';

        while (($chunk = $request->body->read(2)) !== null) {
            Assert::true(\strlen($chunk) <= 2);
            $actual .= $chunk;
        }
        Assert::same($actual, $expected);
        Assert::true($request->body->discard()->isEmpty);
    }

    public function forwardsAnUnknownLengthStream(): void
    {
        $source = new ChunkedReader(new Buffer(), new StringReader("4\r\ndata\r\n0\r\n\r\n"));
        $request = Form::multipart('https://example.com', [new FormPart('file', $source)]);
        Assert::null($request->body->size);
        Assert::false($request->body->isBuffered);
        $request->body->read(8192);
        Assert::same($request->body->read(8192), 'data');
        $request->body->discard();
        Assert::true($source->isComplete);
    }

    public function rejectsAShortUpload(): void
    {
        $request = Form::multipart('https://example.com', [new FormPart('file', new StringReader('a'), size: 2)]);
        Expect::exception(ReadFailed::class);
        $request->body->discard();
    }
}
