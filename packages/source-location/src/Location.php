<?php

declare(strict_types=1);

namespace Thesis\SourceLocation;

use Composer\InstalledVersions;

/**
 * A line of a file. Not readonly as a class: a hooked property cannot be readonly.
 *
 * @api
 */
final class Location
{
    /**
     * The path from the root of the project if the file is under it, the full path otherwise.
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
     * The code of eval() is in a file like "/app/src/foo.php(12) : eval()'d code", nested eval() appends more:
     * its location is the line of the outermost eval().
     *
     * @param non-empty-string $file
     * @param positive-int $line
     */
    public static function of(string $file, int $line): self
    {
        if (preg_match("/^(.+?)\\((\\d+)\\) : eval\\(\\)'d code/", $file, $matches) === 1) {
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
     * @return ?non-empty-string with a trailing separator
     */
    private static function projectRoot(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        // Like "/app/vendor/composer/../../": resolved, it can be compared with the paths of files.
        $root = realpath(InstalledVersions::getRootPackage()['install_path']);

        if ($root === false || $root === \DIRECTORY_SEPARATOR) {
            return null;
        }

        return $root . \DIRECTORY_SEPARATOR;
    }
}
