<?php

declare(strict_types=1);

namespace Thesis\Log\Stub;

use Thesis\Log\Formatter;
use Thesis\Log\Record;

final readonly class CallbackFormatter implements Formatter
{
    /**
     * @var \Closure(Record): string
     */
    private \Closure $callback;

    /**
     * @param callable(Record): string $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    #[\Override]
    public function format(Record $record): string
    {
        return ($this->callback)($record);
    }
}
