<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Thesis\Monorepo\Generator;
use Thesis\Monorepo\Monorepo;

/**
 * A throwaway monorepo in the system temp directory, removed when the object is destroyed.
 */
final readonly class Fixture
{
    public string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/monorepo-' . bin2hex(random_bytes(8));
        $this->file('monorepo.php', <<<'PHP'
            <?php

            return new Thesis\Monorepo\Config(php: '^8.5', version: '^0.1');
            PHP);
        $this->file('composer.json', "{\"name\": \"thesis/monorepo\"}\n");
        $this->file('skills.json', "{}\n");
        $this->file('LICENSE', "MIT\n");
        $this->file('AGENTS.md', "# Fixture\n\n" . Generator::CATALOG_START . "\n" . Generator::CATALOG_END . "\n");
    }

    /**
     * @param string $arguments named arguments of Manifest after the description, as PHP code
     */
    public function package(string $dir, string $arguments): self
    {
        $this->file("packages/{$dir}/package.php", <<<PHP
            <?php

            use Thesis\\Monorepo\\Layer;

            return new Thesis\\Monorepo\\Manifest(description: 'Package {$dir}.', {$arguments});
            PHP);
        $this->file("packages/{$dir}/AGENTS.md", "# {$dir}\n");

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
        if (!is_dir($this->root)) {
            return;
        }

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
    }
}
