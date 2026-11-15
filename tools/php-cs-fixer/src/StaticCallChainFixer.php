<?php

declare(strict_types=1);

namespace Thesis\PhpCsFixer;

use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;

/**
 * @api
 */
final class StaticCallChainFixer implements FixerInterface, WhitespacesAwareFixerInterface
{
    private const array CLASS_NAME = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC];
    private const array OPERATOR = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    private WhitespacesFixerConfig $whitespaces;

    public function __construct()
    {
        $this->whitespaces = new WhitespacesFixerConfig();
    }

    public function getName(): string
    {
        return 'Thesis/static_call_chain';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'A multiline chain of calls that starts with a static call puts `::` and every next call '
                . 'on its own line, one level deeper than the line of the class.',
            [new CodeSample("<?php\n\$a = Foo::create()->bar()\n    ->baz()\n;\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_DOUBLE_COLON);
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if (!$tokens[$index]->isGivenKind(T_DOUBLE_COLON)) {
                continue;
            }

            $class = self::staticAccessClass($tokens, $index);
            $operators = $class === null ? [] : self::chainOperators($tokens, $index);

            if ($class === null || !self::isMultiline($tokens, $operators)) {
                continue;
            }

            $whitespace = $this->whitespaces->getLineEnding()
                . self::lineIndent($tokens, $class)
                . $this->whitespaces->getIndent();

            // From the end: an inserted token moves the indexes after it.
            foreach ([...array_reverse($operators), $index] as $operator) {
                $tokens->ensureWhitespaceAtIndex($operator - 1, 1, $whitespace);
            }
        }
    }

    /**
     * After no_space_around_double_colon and method_chaining_indentation: they see `Foo::create()` on one line
     * and indent the chain and the arguments of its calls from the line of the class. Before statement_indentation:
     * it indents the arguments of the static call from its new line.
     */
    public function getPriority(): int
    {
        return -1;
    }

    public function supports(\SplFileInfo $file): bool
    {
        return true;
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespaces = $config;
    }

    /**
     * The class of `Foo::`, null for `$foo->bar::`, `new Foo()::` and `$foo::`.
     */
    private static function staticAccessClass(Tokens $tokens, int $doubleColon): ?int
    {
        $class = $tokens->getPrevMeaningfulToken($doubleColon);

        if ($class === null || !$tokens[$class]->isGivenKind(self::CLASS_NAME)) {
            return null;
        }

        $before = $tokens->getPrevMeaningfulToken($class);

        if ($before !== null && $tokens[$before]->isGivenKind([...self::OPERATOR, T_NEW])) {
            return null;
        }

        return $class;
    }

    /**
     * The operators of the calls and property fetches after `::method()`, empty if `::` is not a call.
     *
     * @return list<int>
     */
    private static function chainOperators(Tokens $tokens, int $doubleColon): array
    {
        $method = $tokens->getNextMeaningfulToken($doubleColon);
        $end = $method === null ? null : $tokens->getNextMeaningfulToken($method);

        if ($end === null || !$tokens[$method]->isGivenKind(T_STRING) || !$tokens[$end]->equals('(')) {
            return [];
        }

        $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $end);
        $operators = [];

        while (($next = $tokens->getNextMeaningfulToken($end)) !== null) {
            if ($tokens[$next]->equals('(')) {
                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $next);
            } elseif ($tokens[$next]->equals('[')) {
                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_INDEX_SQUARE_BRACE, $next);
            } elseif ($tokens[$next]->isGivenKind(self::OPERATOR)) {
                $operators[] = $next;
                $end = $tokens->getNextMeaningfulToken($next);

                // A dynamic name (`->{$name}`, `->$name()`) ends the part of the chain we follow.
                if ($end === null || !$tokens[$end]->isGivenKind(T_STRING)) {
                    break;
                }
            } else {
                break;
            }
        }

        return $operators;
    }

    /**
     * @param list<int> $operators
     */
    private static function isMultiline(Tokens $tokens, array $operators): bool
    {
        foreach ($operators as $operator) {
            $before = $tokens[$operator - 1];

            if ($before->isWhitespace() && str_contains($before->getContent(), "\n")) {
                return true;
            }
        }

        return false;
    }

    private static function lineIndent(Tokens $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $content = $tokens[$i]->getContent();
            $lineBreak = strrpos($content, "\n");

            if ($lineBreak !== false && $tokens[$i]->isWhitespace()) {
                return substr($content, $lineBreak + 1);
            }
        }

        return '';
    }
}
