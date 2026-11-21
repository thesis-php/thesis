<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Thesis\Monorepo\Internal\Series;

/**
 * A version that the release publishes: X.Y.Z or a pre-release of it, X.Y.Z-alphaN, -betaN or -RCN.
 * A tag of it may start with v, composer reads both as one version.
 *
 * @internal
 */
final class Version
{
    private const string PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|RC)([1-9]\d*))?$/D';

    /**
     * The stages of a pre-release, in their order: the stable version comes after all of them.
     */
    private const array STAGES = ['alpha', 'beta', 'RC'];

    public readonly bool $preRelease;

    /**
     * @var non-empty-string
     */
    public string $tag {
        get => 'v' . $this->toString();
    }

    /**
     * @var non-empty-list<self> this stable version and its first pre-release of every stage
     */
    private array $withFirstPreReleases {
        get {
            $first = [$this];

            foreach (self::STAGES as $stage) {
                $first[] = $this->preRelease($stage, number: 1);
            }

            return $first;
        }
    }

    public static function parse(string $version): ?self
    {
        if (preg_match(self::PATTERN, $version, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return new self(
            major: (int) $match[1],
            minor: (int) $match[2],
            patch: (int) $match[3],
            stage: $match[4] ?? null,
            number: (int) ($match[5] ?? 0),
        );
    }

    /**
     * The versions that may follow the latest release in the series: a typo like 0.1.14 for 0.1.4
     * would publish a version for good.
     *
     * @return non-empty-list<self>
     */
    public static function next(Series $series, ?self $last): array
    {
        if ($last === null) {
            return self::stable($series->major, $series->minor, patch: 0)->withFirstPreReleases;
        }

        if (!$last->preRelease) {
            return self::stable($last->major, $last->minor, $last->patch + 1)->withFirstPreReleases;
        }

        $stable = self::stable($last->major, $last->minor, $last->patch);
        $next = [$stable, $stable->preRelease($last->stage, $last->number + 1)];
        $later = \array_slice(self::STAGES, (int) array_search($last->stage, self::STAGES, strict: true) + 1);

        foreach ($later as $stage) {
            $next[] = $stable->preRelease($stage, number: 1);
        }

        return $next;
    }

    private static function stable(int $major, int $minor, int $patch): self
    {
        return new self($major, $minor, $patch, stage: null, number: 0);
    }

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly ?string $stage,
        public readonly int $number,
    ) {
        $this->preRelease = $stage !== null;
    }

    public function isOf(Series $series): bool
    {
        return $this->major === $series->major && $this->minor === $series->minor;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        $version = $this->major . '.' . $this->minor . '.' . $this->patch;

        return $this->stage === null ? $version : $version . '-' . $this->stage . $this->number;
    }

    private function preRelease(?string $stage, int $number): self
    {
        return new self($this->major, $this->minor, $this->patch, $stage, $number);
    }
}
