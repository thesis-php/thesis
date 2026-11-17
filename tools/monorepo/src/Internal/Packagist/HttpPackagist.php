<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Packagist;

use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\MonorepoException;
use function Thesis\exceptionally;

/**
 * The credentials are needed only to write: a dry run reads without them.
 *
 * @internal
 */
final readonly class HttpPackagist implements Packagist
{
    private const string URL = 'https://packagist.org/';

    /**
     * @param ?non-empty-string $username
     * @param ?non-empty-string $token the main API token: creating a package is not allowed with the safe one
     */
    public function __construct(
        private ?string $username = null,
        private ?string $token = null,
    ) {}

    #[\Override]
    public function exists(string $package): bool
    {
        // A 404 is not cached by Packagist, so a package created by the previous run is found by the next one.
        return $this->request('GET', \sprintf('packages/%s.json', $package)) !== null;
    }

    #[\Override]
    public function create(string $repository): void
    {
        $created = $this->request('POST', 'api/create-package', ['repository' => $repository]);

        if ($created === null) {
            throw new MonorepoException(\sprintf('Packagist: POST api/create-package of %s: not found.', $repository));
        }
    }

    #[\Override]
    public function hook(): array
    {
        [$username, $token] = $this->credentials();

        return [
            'url' => self::HOOK_URL . '?username=' . rawurlencode($username),
            'content_type' => 'json',
            'secret' => $token,
            'insecure_ssl' => '0',
        ];
    }

    /**
     * @return array{non-empty-string, non-empty-string}
     */
    private function credentials(): array
    {
        if ($this->username === null || $this->token === null) {
            throw new MonorepoException('Set PACKAGIST_USERNAME and PACKAGIST_TOKEN (the main API token) to write to Packagist.');
        }

        return [$this->username, $this->token];
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param ?array<array-key, mixed> $body sent with the credentials
     * @return ?array<array-key, mixed> null on 404
     */
    private function request(string $method, string $path, ?array $body = null): ?array
    {
        $header = ['Content-Type: application/json', 'User-Agent: thesis-monorepo'];

        if ($body !== null) {
            [$username, $token] = $this->credentials();
            $header[] = \sprintf('Authorization: Bearer %s:%s', $username, $token);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $header,
                'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        try {
            $content = exceptionally(static fn() => file_get_contents(self::URL . $path, context: $context));
        } catch (\ErrorException $error) {
            throw new MonorepoException(
                message: \sprintf('Packagist: %s %s: %s.', $method, $path, $error->getMessage()),
                previous: $error,
            );
        }

        $status = self::status(http_get_last_response_headers() ?? []);

        if ($status === null) {
            throw new MonorepoException(\sprintf('Packagist: %s %s: no response.', $method, $path));
        }

        if ($status === 404) {
            return null;
        }

        $response = $content === '' ? [] : Json::decode($content, \sprintf('Packagist: %s %s', $method, $path));

        // An error may come with 200 too: {"status": "error", "message": ...}
        if ($status >= 400 || ($response['status'] ?? null) === 'error') {
            throw new MonorepoException(\sprintf(
                'Packagist: %s %s: %d %s',
                $method,
                $path,
                $status,
                json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ));
        }

        return $response;
    }

    /**
     * @param list<string> $headers
     */
    private static function status(array $headers): ?int
    {
        $status = null;

        // After a redirect the headers hold a status line per response, the last one is the final response.
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
