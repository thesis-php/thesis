<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\AbstractBody;
use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\HttpClient\FormPart;
use Thesis\Io\Closer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\StreamClosed;
use Thesis\Io\StringReader;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * @internal
 */
final class MultipartBody extends AbstractBody
{
    #[\Override]
    public readonly ?int $size;

    #[\Override]
    public readonly bool $isBuffered;

    #[\Override]
    public bool $mayHaveTrailers {
        get => false;
    }

    /**
     * @var array<int, array{Reader, ?non-negative-int}>
     */
    private array $segments = [];

    private int $index = 0;

    private int $offset = 0;

    private bool $reading = false;

    private bool $closed = false;

    /**
     * @param iterable<FormPart> $parts
     */
    public function __construct(string $boundary, iterable $parts)
    {
        $buffered = true;
        $prefix = '';
        foreach ($parts as $part) {
            $head = $prefix . "--{$boundary}\r\nContent-Disposition: form-data; name=" . self::quote($part->name);
            $type = $part->contentType;
            if ($part->filename !== null) {
                $head .= '; filename=' . self::quote($part->filename);
                $type ??= 'application/octet-stream';
            }
            if ($type !== null) {
                if (preg_match('/[\x00-\x1f\x7f]/', $type) === 1) {
                    throw new \ValueError('A multipart content type cannot contain control characters.');
                }
                $head .= "\r\nContent-Type: " . $type;
            }
            $head .= "\r\n\r\n";
            $this->segments[] = [new StringReader($head), \strlen($head)];
            $value = $part->value;
            $reader = \is_string($value) ? new StringReader($value) : $value;
            $size = \is_string($value) ? \strlen($value) : $part->size;
            if ($reader instanceof StringReader || $reader instanceof Body) {
                $size ??= $reader->size;
            }
            $this->segments[] = [$reader, $size];
            $buffered = $buffered
                && ($reader instanceof StringReader || ($reader instanceof Body && $reader->isBuffered));
            $prefix = "\r\n";
        }
        $end = $prefix . "--{$boundary}--\r\n";
        $this->segments[] = [new StringReader($end), \strlen($end)];
        $total = 0;
        foreach ($this->segments as [, $size]) {
            if ($size === null || $size > PHP_INT_MAX - $total) {
                $total = null;
                break;
            }
            $total += $size;
        }

        $this->size = $total;
        $this->isBuffered = $buffered;
    }

    private function __clone() {}

    public function read(int $max): ?string
    {
        if ($this->closed) {
            throw new StreamClosed('The multipart body is closed.');
        }
        if ($this->reading) {
            throw new ConcurrentRead('The multipart body already has a read in progress.');
        }
        $this->reading = true;

        try {
            while (isset($this->segments[$this->index])) {
                [$reader, $size] = $this->segments[$this->index];
                $remaining = $size === null ? null : $size - $this->offset;
                if ($remaining === null || $remaining > 0) {
                    $bytes = $reader->read(min($max, $remaining ?? $max));
                    // @phpstan-ignore if.alwaysFalse (another fiber may close the body during read)
                    if ($this->closed) {
                        throw new StreamClosed('The multipart body was closed during a read.');
                    }
                    if ($bytes !== null) {
                        $this->offset += \strlen($bytes);

                        return $bytes;
                    }
                    if ($remaining !== null) {
                        throw new ReadFailed('A multipart field ended before its declared length.');
                    }
                }
                ++$this->index;
                $this->offset = 0;
            }

            return null;
        } finally {
            $this->reading = false;
        }
    }

    public function discard(): Headers
    {
        while ($this->read(8192) !== null);

        return EMPTY_HEADERS;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->segments as [$reader]) {
            if ($reader instanceof Closer) {
                $reader->close();
            }
        }
        $this->segments = [];
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new \ValueError('A multipart name contains an invalid control character.');
        }

        return '"' . strtr($value, ['"' => '%22', "\r" => '%0D', "\n" => '%0A', '\\' => '%5C']) . '"';
    }
}
