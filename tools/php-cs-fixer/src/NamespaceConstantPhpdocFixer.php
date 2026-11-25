<?php

declare(strict_types=1);

namespace Thesis\PhpCsFixer;

use PhpCsFixer\DocBlock\DocBlock;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\WhitespacesAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use PhpCsFixer\WhitespacesFixerConfig;

/**
 * @api
 */
final class NamespaceConstantPhpdocFixer implements FixerInterface, WhitespacesAwareFixerInterface
{
    private WhitespacesFixerConfig $whitespaces;

    public function __construct()
    {
        $this->whitespaces = new WhitespacesFixerConfig();
    }

    public function getName(): string
    {
        return 'Thesis/namespace_constant_phpdoc';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        $sample = new CodeSample("<?php\n/** @api */\nconst LIMIT = 16;\n");

        return new FixerDefinition('Expand single-line PHPDoc on namespace constants.', [$sample]);
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_CONST) && $tokens->isTokenKindFound(T_DOC_COMMENT);
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        $members = new TokensAnalyzer($tokens)->getClassyElements();

        foreach (array_keys($tokens->findGivenKind(T_CONST)) as $index) {
            if (isset($members[$index])) {
                continue;
            }

            do {
                $index = $tokens->getPrevNonWhitespace($index);

                if ($index !== null && $tokens[$index]->isGivenKind(CT::T_ATTRIBUTE_CLOSE)) {
                    $index = $tokens->findBlockStart(Tokens::BLOCK_TYPE_ATTRIBUTE, $index);
                }
            } while ($index !== null && $tokens[$index]->isGivenKind([T_ATTRIBUTE, T_COMMENT]));

            if ($index === null || !$tokens[$index]->isGivenKind(T_DOC_COMMENT)) {
                continue;
            }

            $doc = new DocBlock($tokens[$index]->getContent());
            $indent = WhitespacesAnalyzer::detectIndent($tokens, $index);
            $doc->makeMultiLine($indent, $this->whitespaces->getLineEnding());
            $tokens[$index] = new Token([T_DOC_COMMENT, $doc->getContent()]);
        }
    }

    public function getPriority(): int
    {
        return 4;
    }

    public function supports(\SplFileInfo $file): bool
    {
        return true;
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespaces = $config;
    }
}
