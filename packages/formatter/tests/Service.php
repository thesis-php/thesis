<?php

declare(strict_types=1);

namespace Thesis\Formatter;

final class Service
{
    public int $count = 0;

    public static function create(): void {}

    public function handle(int $id): void {}

    public function __invoke(): void {}

    /**
     * @return array{\Closure(): void, int} a closure and the line it is declared at
     */
    public function closure(): array
    {
        return [static function (): void {}, __LINE__];
    }
}
