<?php

declare(strict_types=1);

namespace Thesis\SourceLocation;

use Composer\InstalledVersions;

/**
 * A location identifies a line in a file. The class is not `readonly`: PHP forbids hooks on readonly properties.
 *
 * @api
 */
final class Location
{
    /**
     * The code of eval() is in a file like "/app/src/foo.php(12) : eval()'d code", nested eval() appends more:
     * its location is the line of the outermost eval().
     *
     * @param non-empty-string $file
     * @param positive-int $line
     */
    public static function of(string $file, int $line): self
    {
        // the lazy (.+?) of /s takes any prefix, so the leftmost match starts at the beginning anyway
        // @infection-ignore PregMatchRemoveCaret
        if (preg_match("/^(.+?)\\(([1-9]\\d*)\\) : eval\\(\\)'d code/s", $file, $matches) === 1) {
            /** @var non-empty-string $evalFile */
            $evalFile = $matches[1];
            /** @var positive-int $evalLine */
            $evalLine = (int) $matches[2];

            return new self($evalFile, $evalLine);
        }

        return new self($file, $line);
    }

    /**
     * Where the function is declared, null for an internal one.
     */
    public static function ofFunction(\ReflectionFunctionAbstract $function): ?self
    {
        $file = $function->getFileName();
        $line = $function->getStartLine();

        if ($file === false || $file === '' || $line === false) {
            return null;
        }

        return self::of($file, $line);
    }

    /**
     * The property holds a path relative to the project root for files inside the project.
     * For files outside the project, it holds the full path.
     *
     * @var non-empty-string
     */
    public string $shortFile {
        get {
            /** @var ?non-empty-string $root */
            static $root = self::projectRoot();

            if ($root !== null && str_starts_with($this->file, $root) && \strlen($this->file) > \strlen($root)) {
                /** @var non-empty-string */
                return substr($this->file, \strlen($root));
            }

            return $this->file;
        }
    }

    /**
     * Use {@see of()} for a file that may be the code of eval().
     *
     * @param non-empty-string $file
     * @param positive-int $line
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
    ) {}

    /**
     * The full path: exact, and a terminal or an IDE opens it. {@see $shortFile} is for reading.
     *
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->file . ':' . $this->line;
    }

    /**
     * A relative path identifies a file inside the project. A full path identifies a file outside the project.
     * Using "/" as the project root would shorten even system paths. The method keeps full paths in that case.
     *
     * @return ?non-empty-string with a trailing separator
     */
    private static function projectRoot(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        // Like "/app/vendor/composer/../../": resolved, it can be compared with the paths of files.
        // A root outside open_basedir warns, and a path that is not shortened is an answer, not an error.
        $root = @realpath(InstalledVersions::getRootPackage()['install_path']);

        if ($root === false || $root === \DIRECTORY_SEPARATOR) {
            return null;
        }

        return $root . \DIRECTORY_SEPARATOR;
    }
}
