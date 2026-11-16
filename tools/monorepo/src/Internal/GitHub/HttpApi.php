<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\GitHub;

use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\MonorepoException;
use function Thesis\exceptionally;

/**
 * @internal
 */
final readonly class HttpApi implements Api
{
    private const string URL = 'https://api.github.com/';

    /**
     * @param non-empty-string $token
     */
    public function __construct(
        private string $token,
    ) {}

    #[\Override]
    public function get(string $path): ?array
    {
        return $this->request('GET', $path);
    }

    #[\Override]
    public function send(string $method, string $path, array $body): array
    {
        return $this->request($method, $path, $body)
            ?? throw new MonorepoException(\sprintf('GitHub: %s %s: not found.', $method, $path));
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param ?array<array-key, mixed> $body
     * @return ?array<array-key, mixed> null on 404
     */
    private function request(string $method, string $path, ?array $body = null): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                    'User-Agent: thesis-monorepo',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
                'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        try {
            $content = exceptionally(static fn() => file_get_contents(self::URL . $path, context: $context));
        } catch (\ErrorException $error) {
            throw new MonorepoException(
                message: \sprintf('GitHub: %s %s: %s.', $method, $path, $error->getMessage()),
                previous: $error,
            );
        }

        $status = self::status(http_get_last_response_headers() ?? []);

        if ($status === null) {
            throw new MonorepoException(\sprintf('GitHub: %s %s: no response.', $method, $path));
        }

        if ($status === 404) {
            return null;
        }

        $response = $content === '' ? [] : Json::decode($content, \sprintf('GitHub: %s %s', $method, $path));

        if ($status >= 400) {
            throw new MonorepoException(\sprintf(
                'GitHub: %s %s: %d %s',
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
