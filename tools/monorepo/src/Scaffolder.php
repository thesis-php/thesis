<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

final readonly class Scaffolder
{
    /**
     * @return non-empty-string path of the new package
     */
    public static function create(string $root, string $dir, Layer $layer, string $description): string
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $dir) !== 1) {
            throw new MonorepoException(\sprintf('Package name "%s" must be kebab-case.', $dir));
        }

        $description = rtrim(trim($description), '.');

        if ($description === '') {
            throw new MonorepoException('Description must not be empty.');
        }

        $description .= '.';
        $path = Monorepo::PACKAGES_DIR . '/' . $dir;

        if (file_exists($root . '/' . $path)) {
            throw new MonorepoException(\sprintf('%s already exists.', $path));
        }

        $name = Monorepo::VENDOR . '/' . $dir;
        $layerCase = $layer->name;
        $descriptionLiteral = var_export($description, true);

        foreach (['', '/src', '/tests'] as $sub) {
            mkdir($root . '/' . $path . $sub, recursive: true);
        }

        $files = [
            Monorepo::MANIFEST => <<<PHP
                <?php

                declare(strict_types=1);

                use Thesis\\Monorepo\\Layer;
                use Thesis\\Monorepo\\Manifest;

                return new Manifest(
                    description: {$descriptionLiteral},
                    layer: Layer::{$layerCase},
                );

                PHP,
            'src/.gitkeep' => '',
            'tests/.gitkeep' => '',
            'README.md' => <<<MD
                # {$name}

                {$description}

                ## Installation

                ```shell
                composer require {$name}
                ```

                MD,
            'AGENTS.md' => <<<MD
                # {$name}

                {$description} Layer: `{$layer->value}`.

                This file is for agents changing this package. It is not loaded while an agent works in another package,
                so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`:
                skills ship with the package and reach its users through llm/skills.

                ## Invariants that must not be broken

                - None yet. Record a rule here when breaking it is a bug that tests may not catch.

                ## Decisions

                - None yet. Record what was rejected and why, so that it is not proposed again.

                MD,
        ];

        foreach ($files as $file => $content) {
            file_put_contents($root . '/' . $path . '/' . $file, $content);
        }

        copy($root . '/LICENSE', $root . '/' . $path . '/LICENSE');

        return $path;
    }
}
