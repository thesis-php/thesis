<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * Declared order matters: a package may depend on packages of its own layer or of the layers above it in this list.
 *
 * @api
 */
enum Layer: string
{
    case Primitive = 'primitive';
    case Runtime = 'runtime';
    case Io = 'io';
    case Protocol = 'protocol';
    case Application = 'application';

    /**
     * @return non-empty-string
     */
    public function description(): string
    {
        return match ($this) {
            self::Primitive => 'Pure building blocks: bytes, time, small value objects. No event loop.',
            self::Runtime => 'Structured concurrency on top of revolt/event-loop.',
            self::Io => 'Streams, sockets, files, processes.',
            self::Protocol => 'Clients and servers of wire protocols.',
            self::Application => 'Frameworks and integrations: DI, message bus, ORM, console.',
        };
    }

    public function canDependOn(self $layer): bool
    {
        return $layer->rank() <= $this->rank();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    private function rank(): int
    {
        return (int) array_search(
            needle: $this,
            haystack: self::cases(),
            strict: true,
        );
    }
}
