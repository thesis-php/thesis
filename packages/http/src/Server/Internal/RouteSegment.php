<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

/**
 * @internal
 */
final readonly class RouteSegment
{
    /**
     * @var non-empty-list<?string>
     */
    private array $requirements;

    private ?string $compound;

    /**
     * @param non-empty-list<string> $literals
     * @param non-empty-list<?string> $requirements
     */
    public function __construct(
        private array $literals,
        array $requirements,
        string $pattern,
    ) {
        $compiled = [];
        foreach ($requirements as $fragment) {
            $compiled[] = $fragment === null ? null : self::requirement($fragment, $pattern);
        }
        $this->requirements = $compiled;
        $this->compound = \count($requirements) > 1 ? $this->compoundExpression($requirements) : null;
    }

    public static function requirement(string $regex, string $pattern): string
    {
        $regex = "\x01\\A(?:{$regex})\\z\x01sD";
        if (@preg_match($regex, '') === false) {
            throw new \InvalidArgumentException("Invalid requirement in '{$pattern}'.");
        }

        return $regex;
    }

    /**
     * @param non-empty-list<?string> $requirements
     */
    private function compoundExpression(array $requirements): ?string
    {
        $regex = '\A';
        foreach ($requirements as $index => $fragment) {
            $regex .= preg_quote($this->literals[$index] ?? '', '~');
            if ($fragment === null) {
                $regex .= '(.+?)';

                continue;
            }
            // Lazy positive repetitions preserve the shortest valid capture at each boundary.
            $simple = '~\A(?:\[[a-zA-Z0-9_-]+\]|\\\[dws])([+]|\{[1-9][0-9]*(?:,[0-9]*)?\})?\z~';
            if (preg_match($simple, $fragment, $parts) !== 1) {
                return null;
            }
            $regex .= '(' . $fragment . (isset($parts[1]) ? '?' : '') . ')';
        }
        $suffix = $this->literals[\count($requirements)] ?? '';
        $regex = '~' . $regex . preg_quote($suffix, '~') . '\z~sD';

        return @preg_match($regex, '') === false ? null : $regex;
    }

    /**
     * @return list<string>|null
     */
    public function match(string $segment): ?array
    {
        if ($this->compound !== null) {
            $matched = preg_match($this->compound, $segment, $captures);
            if ($matched === 1) {
                // The generated expression contains only numbered captures.
                /** @var non-empty-list<string> $captures */
                return \array_slice($captures, 1);
            }
            if ($matched === 0) {
                return null;
            }
            // If PCRE reaches a limit, try the requirements individually.
        }

        [$prefix] = $this->literals;
        $suffix = array_last($this->literals);
        if (!str_starts_with($segment, $prefix) || !str_ends_with($segment, $suffix)) {
            return null;
        }

        if (\count($this->requirements) === 1) {
            $length = \strlen($segment) - \strlen($prefix) - \strlen($suffix);
            if ($length <= 0) {
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
                if ($end <= $start) {
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
