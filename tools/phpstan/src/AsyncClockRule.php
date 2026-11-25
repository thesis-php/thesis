<?php

declare(strict_types=1);

namespace Thesis\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Internal\Scope as AsyncScope;

/**
 * @implements Rule<FuncCall>
 */
final readonly class AsyncClockRule implements Rule
{
    private string $sourceDirectory;

    public function __construct(
        private ReflectionProvider $reflectionProvider,
        string $sourceDirectory,
    ) {
        $this->sourceDirectory = \dirname(__DIR__, 3) . '/' . $sourceDirectory . '/';
    }

    #[\Override]
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || !str_starts_with($scope->getFile(), $this->sourceDirectory)) {
            return [];
        }

        $function = strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? '');

        if ($function !== 'hrtime' && $function !== 'microtime') {
            return [];
        }

        if ($function === 'hrtime' && $scope->getFunctionName() === 'now' && \in_array(
            needle: $scope->getClassReflection()?->getName(),
            haystack: [Loop::class, AsyncScope::class],
            strict: true,
        )) {
            return [];
        }

        return [
            RuleErrorBuilder
                ::message("{$function}() bypasses virtual time. Read the scope or loop clock instead.")
                ->identifier('thesis.asyncClock')
                ->build(),
        ];
    }
}
