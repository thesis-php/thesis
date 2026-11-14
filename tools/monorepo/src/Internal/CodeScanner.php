<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

/**
 * @internal
 */
final readonly class CodeScanner
{
    /**
     * @return list<string>
     */
    public static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Qualified names under the given namespace. A namespace declaration is skipped: it is the file's own.
     * Relative names are taken as fully qualified, which is right for "use" statements and for names written in full.
     *
     * @return list<Reference>
     */
    public static function names(string $file, string $namespace): array
    {
        $code = file_get_contents($file);
        \assert($code !== false);

        $names = [];
        $afterNamespace = false;

        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if (!$afterNamespace && $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($token->text, '\\');

                if (str_starts_with($name, $namespace)) {
                    $names[] = new Reference($name, $token->line);
                }
            }

            $afterNamespace = $token->is(T_NAMESPACE);
        }

        return $names;
    }

    /**
     * Top-level classes, interfaces, traits, enums, functions and constants with the tags of their PHPDoc.
     * Anonymous classes, closures and Foo::class are not declarations. Braced namespaces are not supported.
     *
     * @return list<Declaration>
     */
    public static function declarations(string $file): array
    {
        $code = file_get_contents($file);
        \assert($code !== false);

        $declarations = [];
        $namespace = '';
        $depth = 0;
        $doc = null;
        $previous = null;
        $pending = null;

        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is(T_DOC_COMMENT)) {
                $doc = $token->text;

                continue;
            }

            if ($token->is([T_WHITESPACE, T_COMMENT])) {
                continue;
            }

            if ($pending === T_NAMESPACE && $token->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $token->text;
            } elseif ($pending !== null && $token->is(T_STRING)) {
                $declarations[] = new Declaration(
                    namespace: $namespace,
                    name: $token->text,
                    line: $token->line,
                    api: $doc !== null && preg_match('/@api\b/', $doc) === 1,
                    internal: $doc !== null && preg_match('/@internal\b/', $doc) === 1,
                );
            }

            $pending = null;

            if ($depth === 0 && $token->is(T_NAMESPACE)) {
                $pending = T_NAMESPACE;
            } elseif (
                $depth === 0
                && $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST])
                && !($previous?->is([T_DOUBLE_COLON, T_NEW]) ?? false)
            ) {
                $pending = $token->id;
            }

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
            } elseif ($token->is('}')) {
                --$depth;
            }

            if ($token->is([';', '{', '}'])) {
                $doc = null;
            }

            $previous = $token;
        }

        return $declarations;
    }
}
