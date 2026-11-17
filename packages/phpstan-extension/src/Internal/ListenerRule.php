<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension\Internal;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A method marked `#[Thesis\Dispatcher\Listener]` must be public and have a body. `listenersOf()` rejects
 * one that is not public only when it runs, and never sees an abstract one: the mark is not inherited,
 * so the implementations of an abstract method are not listeners and nothing fails.
 *
 * @internal
 * @implements Rule<ClassMethod>
 */
final readonly class ListenerRule implements Rule
{
    private const string LISTENER = 'Thesis\Dispatcher\Listener';

    #[\Override]
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    #[\Override]
    public function processNode(Node $method, Scope $scope): array
    {
        $mark = self::mark($method);
        $class = $scope->getClassReflection();

        if ($mark === null || $class === null) {
            return [];
        }

        $name = \sprintf('%s::%s()', $class->getDisplayName(), $method->name->toString());
        $errors = [];

        if (!$method->isPublic()) {
            $errors[] = self::error(
                mark: $mark,
                message: \sprintf('%s is marked #[%s] but is not public: listenersOf() rejects it.', $name, self::LISTENER),
                identifier: 'thesis.listenerNotPublic',
            );
        }

        if ($method->stmts === null) {
            $errors[] = self::error(
                mark: $mark,
                message: \sprintf('%s is marked #[%s] but has no body: the mark is not inherited by its implementations.', $name, self::LISTENER),
                identifier: 'thesis.listenerAbstract',
            );
        }

        return $errors;
    }

    private static function mark(ClassMethod $method): ?Attribute
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === self::LISTENER) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    /**
     * @param non-empty-string $identifier
     */
    private static function error(Attribute $mark, string $message, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder
            ::message($message)
            ->identifier($identifier)
            ->line($mark->getStartLine())
            ->build()
        ;
    }
}
