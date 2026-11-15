<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

use Thesis\Monorepo\Internal\MonorepoException;
use Uri\WhatWg\Url;

/**
 * Where the split repositories are, e.g. https://x-access-token:<token>@github.com/thesis-php.
 * The credentials go to the environment of git (url.<with credentials>.insteadOf <without>), not into its
 * arguments: those are visible to everyone in ps.
 *
 * @internal
 */
final readonly class Remote
{
    public static function parse(string $url): self
    {
        $parsed = Url::parse($url) ?? throw new MonorepoException(
            'SPLIT_REMOTE is not a URL, expected e.g. https://x-access-token:<token>@github.com/thesis-php.',
        );
        $private = rtrim($url, '/');

        if (($parsed->getUsername() ?? '') === '' && ($parsed->getPassword() ?? '') === '') {
            return new self($private, []);
        }

        $public = rtrim($parsed->withUsername(null)->withPassword(null)->toAsciiString(), '/');

        return new self($public, [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'url.' . $private . '/.insteadOf',
            'GIT_CONFIG_VALUE_0' => $public . '/',
        ]);
    }

    /**
     * @param array<string, string> $env
     */
    private function __construct(
        private string $base,
        public array $env,
    ) {}

    public function url(string $package): string
    {
        return $this->base . '/' . $package . '.git';
    }
}
