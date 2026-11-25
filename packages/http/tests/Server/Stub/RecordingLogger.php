<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{mixed, string, array<array-key, mixed>}>
     */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
