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
use PHPStan\Type\ObjectType;

/**
 * A method marked `#[Thesis\Dispatcher\Listener]` must be public and have a body. `listenersOf()` rejects
 * one that is not public only when it runs, and never sees an abstract one: the mark is not inherited,
 * so the implementations of an abstract method are not listeners and nothing fails.
 *
 * The dispatcher calls it with the event and itself: its second parameter must take `Thesis\Dispatcher`,
 * the ones after it must be optional. PHPStan checks that of a callable given to the dispatcher by its type,
 * but `listenersOf()` gives the marked methods as closures of any signature.
 *
 * @internal
 * @implements Rule<ClassMethod>
 */
final readonly class ListenerRule implements Rule
{
    private const string LISTENER = 'Thesis\Dispatcher\Listener';
    private const string DISPATCHER = 'Thesis\Dispatcher';

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
                at: $mark,
                message: \sprintf('%s is marked #[%s] but is not public: listenersOf() rejects it.', $name, self::LISTENER),
                identifier: 'thesis.listenerNotPublic',
            );
        }

        if ($method->stmts === null) {
            $errors[] = self::error(
                at: $mark,
                message: \sprintf('%s is marked #[%s] but has no body: the mark is not inherited by its implementations.', $name, self::LISTENER),
                identifier: 'thesis.listenerAbstract',
            );
        }

        $parameters = $class->getNativeMethod($method->name->toString())->getOnlyVariant()->getParameters();
        $dispatcher = new ObjectType(self::DISPATCHER);

        foreach ($parameters as $index => $parameter) {
            if ($index === 1 && !$parameter->getType()->accepts($dispatcher, strictTypes: true)->yes()) {
                $errors[] = self::error(
                    at: $method->params[$index] ?? $mark,
                    message: \sprintf(
                        'Parameter $%s of %s, marked #[%s], must take %s: the dispatcher passes itself as the second argument.',
                        $parameter->getName(),
                        $name,
                        self::LISTENER,
                        self::DISPATCHER,
                    ),
                    identifier: 'thesis.listenerSecondParameter',
                );
            }

            if ($index > 1 && !$parameter->isOptional()) {
                $errors[] = self::error(
                    at: $method->params[$index] ?? $mark,
                    message: \sprintf(
                        'Parameter $%s of %s, marked #[%s], must be optional: the dispatcher passes the event and itself only.',
                        $parameter->getName(),
                        $name,
                        self::LISTENER,
                    ),
                    identifier: 'thesis.listenerRequiredParameter',
                );
            }
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
    private static function error(Node $at, string $message, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder
            ::message($message)
            ->identifier($identifier)
            ->line($at->getStartLine())
            ->build()
        ;
    }
}
