<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

/**
 * @internal
 */
final readonly class RouteSegment
{
    /**
     * @param non-empty-list<string> $literals
     * @param non-empty-list<?string> $requirements
     */
    public function __construct(
        private array $literals,
        private array $requirements,
    ) {}

    /**
     * @return list<string>|null
     */
    public function match(string $segment): ?array
    {
        [$prefix] = $this->literals;
        if (!str_starts_with($segment, $prefix)) {
            return null;
        }

        if (\count($this->requirements) === 1) {
            $suffix = $this->literals[1] ?? '';
            $length = \strlen($segment) - \strlen($prefix) - \strlen($suffix);
            if ($length <= 0 || !str_ends_with($segment, $suffix)) {
                return null;
            }
            $value = $prefix === '' && $suffix === '' ? $segment : substr($segment, \strlen($prefix), $length);

            return $this->accepts(0, $value) ? [$value] : null;
        }

        $values = [];
        $failed = [];

        return $this->capture($segment, 0, \strlen($prefix), $values, $failed) ? $values : null;
    }

    /**
     * @param list<string> $values
     * @param array<int, array<int, true>> $failed
     */
    private function capture(string $segment, int $index, int $start, array &$values, array &$failed): bool
    {
        if (isset($failed[$index][$start]) || $start >= \strlen($segment)) {
            return false;
        }
        $last = $index === array_key_last($this->requirements);
        $literal = $this->literals[$index + 1] ?? '';
        $end = $start + 1;

        while ($end <= \strlen($segment)) {
            if ($last) {
                $end = \strlen($segment) - \strlen($literal);
                if ($end <= $start || !str_ends_with($segment, $literal)) {
                    break;
                }
            } elseif ($literal !== '') {
                $next = strpos($segment, $literal, $end);
                if ($next === false) {
                    break;
                }
                $end = $next;
            }
            $value = substr($segment, $start, $end - $start);
            if ($this->accepts($index, $value)) {
                $values[] = $value;
                if ($last || $this->capture($segment, $index + 1, $end + \strlen($literal), $values, $failed)) {
                    return true;
                }
                array_pop($values);
            }
            if ($last) {
                break;
            }
            ++$end;
        }
        $failed[$index][$start] = true;

        return false;
    }

    private function accepts(int $index, string $value): bool
    {
        $regex = $this->requirements[$index] ?? null;
        if ($regex === null) {
            return true;
        }
        $result = preg_match($regex, $value);
        if ($result === false) {
            throw new \RuntimeException(preg_last_error_msg());
        }

        return $result === 1;
    }
}
