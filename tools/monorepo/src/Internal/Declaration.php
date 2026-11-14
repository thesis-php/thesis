<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

/**
 * @internal
 */
final readonly class Declaration
{
    public function __construct(
        public string $namespace,
        public string $name,
        public int $line,
        public bool $api,
        public bool $internal,
    ) {}

    public function fullName(): string
    {
        return $this->namespace === '' ? $this->name : $this->namespace . '\\' . $this->name;
    }

    public function inInternalNamespace(): bool
    {
        return str_contains('\\' . $this->namespace . '\\', '\Internal\\');
    }
}
