<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\Tools;
use Thesis\PhpCsFixer\StaticCallChainFixer;

$skipped = Tools::of(Monorepo::load(__DIR__))->fixerSkips;

return new Config()
    ->setFinder(
        Finder
            ::create()
            ->in([__DIR__ . '/tools', __DIR__ . '/packages'])
            ->filter(static fn(SplFileInfo $file) => !in_array($file->getPathname(), $skipped, true))
            ->append(Finder::create()->in(__DIR__)->depth(0)->ignoreDotFiles(false))
            ->append([__DIR__ . '/bin/monorepo']),
    )
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    // The fixer parses syntax up to PHP 8.5 and refuses to run on 8.6. The code has no syntax of 8.6 (agents/php.md).
    // Delete once the fixer supports 8.6.
    ->setUnsupportedPhpVersionAllowed(true)
    ->registerCustomFixers([new StaticCallChainFixer()])
    ->setRules([
        '@PHP8x4Migration' => true,
        '@PHP8x2Migration:risky' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        'Thesis/static_call_chain' => true,
        'attribute_empty_parentheses' => true,
        'blank_line_before_statement' => ['statements' => [
            'continue',
            'declare',
            'default',
            'return',
            'throw',
            'try',
            'while',
        ]],
        'blank_line_between_import_groups' => false,
        'class_attributes_separation' => [
            'elements' => [
                'case' => 'only_if_meta',
                'const' => 'only_if_meta',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'only_if_meta',
            ],
        ],
        // An annotation of Infection is a comment: a PHPDoc before a statement reads as a type for PHPStan.
        'comment_to_phpdoc' => ['ignored_tags' => ['infection-ignore']],
        'concat_space' => ['spacing' => 'one'],
        'date_time_immutable' => true,
        'final_class' => true,
        'final_public_method_for_abstract_class' => true,
        'get_class_to_class_keyword' => true,
        'global_namespace_import' => [
            'import_constants' => false,
            'import_functions' => false,
            'import_classes' => false,
        ],
        'modernize_strpos' => true,
        'multiline_promoted_properties' => true,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
        'no_break_comment' => false,
        'no_redundant_readonly_property' => true,
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => true],
        'no_trailing_whitespace_in_string' => false,
        // A non-nullable property can be unset to release its value. Assigning null would throw TypeError.
        'no_unset_on_property' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_class_elements' => ['order' => ['use_trait']],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'ordered_types' => ['sort_algorithm' => 'none'],
        'phpdoc_add_missing_param_annotation' => false,
        'phpdoc_align' => false,
        'phpdoc_array_type' => true,
        'phpdoc_line_span' => [
            'case' => 'multi',
            'class' => 'multi',
            'const' => 'multi',
            'function' => 'multi',
            'method' => 'multi',
            'property' => 'multi',
            'other' => 'single',
        ],
        'phpdoc_list_type' => false,
        'phpdoc_no_alias_tag' => ['replacements' => [
            'property-write' => 'property',
            'type' => 'var',
            'link' => 'see',
        ]],
        'phpdoc_param_order' => true,
        'phpdoc_separation' => false,
        'phpdoc_tag_casing' => true,
        'phpdoc_to_comment' => false,
        // Both take any class named *Test that extends another for a PHPUnit test, and tests are not marked.
        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,
        'phpdoc_types_order' => false,
        'regular_callable_call' => true,
        'return_assignment' => false,
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'static_lambda' => true,
        'static_private_method' => true,
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters'],
        ],
        // It adds `: void` to closures too, and closures have no return types. PHPStan requires them on methods.
        'void_return' => false,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
;
