<?php

declare(strict_types=1);

namespace Thesis\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;

/**
 * @implements Rule<FileNode>
 */
final readonly class PackageApiRule implements Rule
{
    private string $packagesDirectory;

    public function __construct(
        private FileTypeMapper $phpDocs,
        string $packagesDirectory,
    ) {
        $this->packagesDirectory = \dirname(__DIR__, 3) . '/' . $packagesDirectory;
    }

    #[\Override]
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $prefix = $this->packagesDirectory . '/';
        $file = $scope->getFile();

        if (!str_starts_with($file, $prefix)) {
            return [];
        }

        $parts = explode('/', substr($file, \strlen($prefix)), 3);

        if (($parts[1] ?? null) !== 'src') {
            return [];
        }

        $package = 'thesis\\' . str_replace('-', '', strtolower($parts[0])) . '\\';
        $errors = [];

        foreach ($node->getNodes() as $statement) {
            if ($statement instanceof Namespace_) {
                $namespace = strtolower($statement->name?->toString() ?? '') . '\\';
                $inInternal = str_starts_with($namespace, $package)
                    && \in_array('internal', explode('\\', substr($namespace, \strlen($package))), strict: true);

                foreach ($statement->stmts as $declaration) {
                    $this->checkDeclaration($declaration, $inInternal, $errors);
                }
            } else {
                $this->checkDeclaration($statement, false, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param list<IdentifierRuleError> $errors
     */
    private function checkDeclaration(Node $node, bool $inInternal, array &$errors): void
    {
        if (!$node instanceof ClassLike && !$node instanceof Function_ && !$node instanceof Const_) {
            return;
        }

        $doc = $this->phpDocs->getResolvedPhpDoc(
            fileName: null,
            className: null,
            traitName: null,
            functionName: null,
            docComment: $node->getDocComment()?->getText(),
        )->getPhpDocNodes()[0] ?? null;
        $api = ($doc?->getTagsByName('@api') ?? []) !== [];
        $internal = ($doc?->getTagsByName('@internal') ?? []) !== [];

        if ($api === $internal) {
            $message = 'Mark the declaration with exactly one of @api and @internal.';
        } elseif ($internal !== $inInternal) {
            $message = $inInternal
                ? 'Declarations in the package Internal namespace must be @internal.'
                : 'An @internal declaration belongs in the package Internal namespace.';
        } else {
            return;
        }

        $errors[] = RuleErrorBuilder
            ::message($message)
            ->identifier('thesis.packageApi')
            ->line($node->getStartLine())
            ->build()
        ;
    }
}
