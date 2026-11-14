<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

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

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Qualified names under the given namespace with their lines. A namespace declaration is skipped: it is the file's own.
     * Relative names are taken as fully qualified, which is right for "use" statements and for names written in full.
     *
     * @return list<array{string, int}>
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
                    $names[] = [$name, $token->line];
                }
            }

            $afterNamespace = $token->is(T_NAMESPACE);
        }

        return $names;
    }
}
