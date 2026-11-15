<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Tests\Fixture;

/**
 * Bare repositories of the split packages in a temporary directory.
 */
final readonly class Remotes
{
    public string $dir;

    public Git $git;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/remotes-' . bin2hex(random_bytes(8));
        mkdir($this->dir);
        $this->git = new Git($this->dir);
    }

    public function head(string $package, string $ref): ?string
    {
        $result = $this->git->runAll([['--git-dir', $package . '.git', 'rev-parse', '--verify', '--quiet', $ref]]);

        return ($result[0] ?? null)?->code === 0 ? trim($result[0]->stdout) : null;
    }

    public function __destruct()
    {
        Fixture::remove($this->dir);
    }
}
