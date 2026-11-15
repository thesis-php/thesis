<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

/**
 * A version tag of the monorepo: vX.Y.Z or a pre-release of it, vX.Y.Z-alphaN, -betaN or -RCN (ADR 0002).
 *
 * @internal
 */
final readonly class Version
{
    private const string PATTERN = '/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|RC)([1-9]\d*))?$/D';

    /**
     * A stable version comes after all of its pre-releases.
     */
    private const array STAGES = ['alpha' => 1, 'beta' => 2, 'RC' => 3, '' => 4];

    public bool $preRelease;

    /**
     * @var list<int> what versions are compared by, the most significant first
     */
    private array $order;

    public static function parse(string $tag): ?self
    {
        if (preg_match(self::PATTERN, $tag, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return new self(
            tag: $tag,
            major: (int) $match[1],
            minor: (int) $match[2],
            patch: (int) $match[3],
            stage: $match[4] ?? '',
            number: (int) ($match[5] ?? 0),
        );
    }

    private function __construct(
        public string $tag,
        public int $major,
        public int $minor,
        int $patch,
        string $stage,
        int $number,
    ) {
        $this->preRelease = $stage !== '';
        $this->order = [$major, $minor, $patch, self::STAGES[$stage] ?? 0, $number];
    }

    public function isLowerThan(self $other): bool
    {
        return $this->order < $other->order;
    }

    public function isOfSeries(self $other): bool
    {
        return $this->major === $other->major && $this->minor === $other->minor;
    }
}
