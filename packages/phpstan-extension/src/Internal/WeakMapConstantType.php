<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node\Expr;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;

/**
 * @internal
 */
final class WeakMapConstantType implements ExpressionTypeResolverExtension
{
    /**
     * @var array<string, array<string, Type>>
     */
    private array $files = [];

    public function __construct(
        private readonly ReflectionProvider $reflection,
        private readonly Parser $parser,
        private readonly FileTypeMapper $mapper,
    ) {}

    /**
     * @param Scope&DependencyTracker $scope
     */
    #[\Override]
    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if (!$expr instanceof Expr\ConstFetch) {
            return null;
        }

        $name = $this->reflection->resolveConstantName($expr->name, $scope);

        if ($name === null) {
            return null;
        }

        $constant = $this->reflection->getConstant(new FullyQualified($name), null);
        $file = $constant->getFileName();

        if ($file === null || $constant->getValueType()->getObjectClassNames() !== [\WeakMap::class]) {
            return null;
        }

        $scope->trackFileDependency($file);
        $this->files[$file] ??= $this->types($file);

        return $this->files[$file][$constant->getName()] ?? null;
    }

    /**
     * @return array<string, Type>
     */
    private function types(string $file): array
    {
        $statements = $this->parser->parseFile($file);
        $namespaces = array_values(array_filter($statements, static fn($node) => $node instanceof Stmt\Namespace_));

        // FileTypeMapper resolves top-level PHPDoc in one namespace per file.
        if (\count($namespaces) > 1) {
            return [];
        }

        $types = [];

        foreach ($namespaces[0]->stmts ?? $statements as $statement) {
            if (!$statement instanceof Stmt\Const_ || \count($statement->consts) !== 1) {
                continue;
            }

            $constant = array_first($statement->consts);
            $doc = $statement->getDocComment();

            if ($constant->namespacedName === null || $doc === null || !$constant->value instanceof Expr\New_) {
                continue;
            }

            $tags = $this->mapper->getResolvedPhpDoc($file, null, null, null, $doc->getText())->getVarTags();
            $type = ($tags[0] ?? null)?->getType();

            if ($type !== null && $type->isObject()->yes() && $type->getObjectClassNames() === [\WeakMap::class]) {
                $types[$constant->namespacedName->toString()] = $type;
            }
        }

        return $types;
    }
}
