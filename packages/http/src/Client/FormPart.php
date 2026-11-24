<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Thesis\Io\Reader;

/**
 * @api
 */
final readonly class FormPart
{
    /**
     * @param ?non-negative-int $size number of bytes to upload from a reader, or null to read to EOF
     */
    public function __construct(
        public string $name,
        public string|Reader $value,
        public ?string $filename = null,
        public ?string $contentType = null,
        public ?int $size = null,
    ) {}
}
