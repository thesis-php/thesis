<?php

declare(strict_types=1);

namespace Thesis\Logger;

use Thesis\Async\ScopedValue;
use Thesis\Io\Writer;
use Thesis\Logger\Formatter\Json;

/**
 * Formats and writes records on the calling fiber.
 *
 * @api
 */
final readonly class StreamHandler implements Handler
{
    /**
     * @var ScopedValue<bool>
     */
    private ScopedValue $writing;

    public function __construct(
        private Writer $output,
        private Formatter $formatter = new Json(),
    ) {
        $this->writing = new ScopedValue(false);
    }

    public function __clone()
    {
        $this->writing = clone $this->writing;
    }

    #[\Override]
    public function handle(\Closure $capture): void
    {
        if ($this->writing->get()) {
            return;
        }

        $this->writing->with(true, function () use ($capture): void {
            $this->output->write($this->formatter->format($capture()) . "\n");
        });
    }
}
