<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use function Thesis\exceptionally;

/**
 * The PHP files of a directory that PSR-4 does not load: those without a class, an interface, a trait or an enum.
 * Composer loads them eagerly, so a new file of functions needs no require_once and no line in the manifest.
 *
 * @internal
 */
final readonly class FunctionFiles
{
    private const array CLASS_LIKE = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    // readonly is skipped too, so new readonly class {} reads as new class {}
    private const array IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY];

    /**
     * @param non-empty-string $dir relative to $base
     * @return list<non-empty-string> relative to $base, sorted: the files must not depend on each other at load time
     */
    public static function in(string $base, string $dir): array
    {
        if (!is_dir($base . '/' . $dir)) {
            return [];
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();

            if ($file->getExtension() === 'php' && !self::declaresClassLike($path)) {
                $files[] = $dir . substr($path, \strlen($base . '/' . $dir));
            }
        }

        sort($files);

        return $files;
    }

    private static function declaresClassLike(string $path): bool
    {
        $code = exceptionally(static fn() => file_get_contents($path));
        $previous = null;

        foreach (token_get_all($code) as $token) {
            if (\is_array($token) && \in_array($token[0], self::IGNORED, true)) {
                continue;
            }

            // Task::class and new class {} declare nothing
            $declaration = \is_array($token)
                && \in_array($token[0], self::CLASS_LIKE, true)
                && !(\is_array($previous) && \in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true));

            if ($declaration) {
                return true;
            }

            $previous = $token;
        }

        return false;
    }
}
