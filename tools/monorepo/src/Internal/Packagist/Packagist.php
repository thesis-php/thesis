<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Packagist;

use Thesis\Monorepo\Internal\MonorepoException;

/**
 * Packagist, where composer finds the packages: it registers a package once and learns of every push to it
 * by a webhook of its repository.
 *
 * @internal
 */
interface Packagist
{
    /**
     * Every webhook of Packagist starts with it: the one of its GitHub integration has no username.
     */
    public const string HOOK_URL = 'https://packagist.org/api/github';

    /**
     * @param non-empty-string $package vendor/name
     * @throws MonorepoException
     */
    public function exists(string $package): bool;

    /**
     * @param non-empty-string $repository the URL of the repository
     * @throws MonorepoException
     */
    public function create(string $repository): void;

    /**
     * @return array<non-empty-string, string> the config of the webhook of a repository on GitHub
     * @throws MonorepoException
     */
    public function hook(): array;
}
