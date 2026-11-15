<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use Thesis\Monorepo\Config;
use Thesis\Monorepo\Manifest;

/**
 * @internal
 */
final readonly class Monorepo
{
    public const string VENDOR = 'thesis';
    public const string NAMESPACE = 'Thesis\\';
    public const string PACKAGES_DIR = 'packages';
    public const string CONFIG = 'monorepo.php';
    public const string MANIFEST = 'package.php';

    public static function load(string $root): self
    {
        $config = self::require(
            root: $root,
            file: self::CONFIG,
            class: Config::class,
        );
        $packages = [];
        $dirs = glob($root . '/' . self::PACKAGES_DIR . '/*', GLOB_ONLYDIR);
        \assert($dirs !== false);
        sort($dirs);

        foreach ($dirs as $dir) {
            $basename = basename($dir);
            \assert($basename !== '');
            $manifest = self::PACKAGES_DIR . '/' . $basename . '/' . self::MANIFEST;

            $packages[$basename] = new Package(
                dir: $basename,
                manifest: self::require(
                    root: $root,
                    file: $manifest,
                    class: Manifest::class,
                ),
                hasSkills: is_dir($dir . '/skills'),
            );
        }

        return new self(
            root: $root,
            config: $config,
            packages: $packages,
        );
    }

    /**
     * @param array<non-empty-string, Package> $packages dir => package
     */
    private function __construct(
        public string $root,
        public Config $config,
        public array $packages,
    ) {}

    /**
     * The monorepo package published under this composer name.
     */
    public function package(string $name): ?Package
    {
        $prefix = self::VENDOR . '/';

        return str_starts_with($name, $prefix) ? $this->packages[substr($name, \strlen($prefix))] ?? null : null;
    }

    /**
     * @param array<string, string> $require
     * @return list<non-empty-string> directories of the monorepo packages among the requirements
     */
    public function packagesIn(array $require): array
    {
        $dirs = [];

        foreach (array_keys($require) as $name) {
            $package = $this->package($name);

            if ($package !== null) {
                $dirs[] = $package->dir;
            }
        }

        return $dirs;
    }

    public function read(string $path): ?string
    {
        $content = @file_get_contents($this->root . '/' . $path);

        return $content === false ? null : $content;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function require(string $root, string $file, string $class): object
    {
        if (!is_file($root . '/' . $file)) {
            throw new MonorepoException(\sprintf('%s is missing.', $file));
        }

        $path = $root . '/' . $file;
        $value = (static fn() => require $path)();

        if (!$value instanceof $class) {
            throw new MonorepoException(\sprintf('%s must return an instance of %s.', $file, $class));
        }

        return $value;
    }
}
