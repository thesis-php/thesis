<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Thesis\Monorepo\Internal\MonorepoException;

/**
 * CHANGELOG.md of a package in the format of Keep a Changelog: `## [Unreleased]` first,
 * then a section per version, `## [0.1.4] - 2026-11-16`, the newest first.
 *
 * @internal
 */
final class Changelog
{
    private const string UNRELEASED = 'Unreleased';
    private const string HEADING = '/^## \[([^\]]+)\](.*)$/D';
    private const string DATE = '/^ - (\d{4})-(\d{2})-(\d{2})[ \t]*$/D';

    /**
     * A link reference definition at the bottom of a changelog belongs to no section.
     */
    private const string LINK = '/^\[[^\]]+\]:\s/';

    /**
     * A line that opens or closes a fenced code block: a heading inside one is an example, not a section.
     */
    private const string FENCE = '/^ {0,3}(```|~~~)/';

    /**
     * @param non-empty-string $path for errors
     */
    public static function parse(string $content, string $path): self
    {
        $sections = [];
        $fenced = false;

        $lines = preg_split('/\R/', $content);

        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match(self::FENCE, $line) === 1) {
                $fenced = !$fenced;
            }

            if (!$fenced && preg_match(self::HEADING, $line, $heading) === 1) {
                $sections[] = ['version' => $heading[1], 'date' => $heading[2], 'lines' => [], 'links' => false];

                continue;
            }

            $last = array_key_last($sections);

            if ($last === null) {
                continue;
            }

            if (!$fenced && preg_match(self::LINK, $line) === 1) {
                $sections[$last]['links'] = true;
            }

            if (!$sections[$last]['links']) {
                $sections[$last]['lines'][] = $line;
            }
        }

        $unreleased = array_shift($sections);

        if ($unreleased === null || $unreleased['version'] !== self::UNRELEASED) {
            throw new MonorepoException(\sprintf('%s: the first section must be ## [%s].', $path, self::UNRELEASED));
        }

        $top = $sections[0] ?? null;

        return new self(
            path: $path,
            unreleased: trim(implode("\n", $unreleased['lines'])),
            top: $top === null ? null : [
                'version' => $top['version'],
                'dated' => self::isDate($top['date']),
                'notes' => trim(implode("\n", $top['lines'])),
            ],
        );
    }

    /**
     * The version right under [Unreleased], null if there is none yet.
     */
    public ?string $topVersion {
        get => $this->top['version'] ?? null;
    }

    /**
     * @param non-empty-string $path
     * @param ?array{version: string, dated: bool, notes: string} $top the section right under [Unreleased]
     */
    private function __construct(
        public readonly string $path,
        public readonly string $unreleased,
        private readonly ?array $top,
    ) {}

    /**
     * The version right under [Unreleased] with the notes of its release.
     *
     * @return array{version: Version, notes: non-empty-string}
     * @throws MonorepoException if there is no such version, it has no date or no notes
     */
    public function top(): array
    {
        if ($this->top === null) {
            throw new MonorepoException(\sprintf('%s: no version under [%s].', $this->path, self::UNRELEASED));
        }

        $version = Version::parse($this->top['version']);

        if ($version === null || str_starts_with($this->top['version'], 'v') || !$this->top['dated']) {
            throw new MonorepoException(\sprintf(
                '%s: [%s] is not a heading of a release: write ## [X.Y.Z] - YYYY-MM-DD'
                    . ' or a pre-release X.Y.Z-alphaN, -betaN, -RCN.',
                $this->path,
                $this->top['version'],
            ));
        }

        $notes = $this->top['notes'];

        if ($notes === '') {
            throw new MonorepoException(\sprintf(
                '%s: [%s] lists no changes, they are the notes of its release.',
                $this->path,
                $this->top['version'],
            ));
        }

        return ['version' => $version, 'notes' => $notes];
    }

    private static function isDate(string $heading): bool
    {
        return preg_match(self::DATE, $heading, $date) === 1
            && checkdate(month: (int) $date[2], day: (int) $date[3], year: (int) $date[1]);
    }
}
