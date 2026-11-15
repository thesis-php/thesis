<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Thesis\Monorepo\Internal\Generator;
use Thesis\Monorepo\Internal\Monorepo;

final readonly class Fixture
{
    public string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/monorepo-' . bin2hex(random_bytes(8));
        $this->file('monorepo.php', <<<'PHP'
            <?php

            return new Thesis\Monorepo\Config(
                php: '^8.5',
                version: '^0.1',
                description: 'Fixture.',
                copyrightHolder: 'Fixture',
                licenseSince: 2026,
            );
            PHP);
        $this->file('skills.json', "{}\n");
        $this->file('AGENTS.md', "# Fixture\n\n" . Generator::CATALOG_START . "\n" . Generator::CATALOG_END . "\n");
    }

    /**
     * @param string $arguments named arguments of Manifest after the description and the license year, as PHP code
     */
    public function package(string $dir, string $arguments = '', int $licenseSince = 2026): self
    {
        $this->file("packages/{$dir}/package.php", <<<PHP
            <?php

            return new Thesis\\Monorepo\\Manifest(
                description: 'Package {$dir}.',
                licenseSince: {$licenseSince},
                {$arguments}
            );
            PHP);

        return $this;
    }

    public function file(string $path, string $content): self
    {
        $file = $this->root . '/' . $path;

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), recursive: true);
        }

        file_put_contents($file, $content);

        return $this;
    }

    public function load(): Monorepo
    {
        return Monorepo::load($this->root);
    }

    public function __destruct()
    {
        self::remove($this->root);
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
