<?php

declare(strict_types=1);

// The style is PER Coding Style (`@PER-CS` follows the current version of the
// standard) and the PHP 8.3 migration set, with the additions below.
//
// The migration set is named, not derived from the running PHP, so the fixer
// asks for the same code on PHP 8.3, 8.4 and 8.5. On a PHP newer than the
// Composer floor it prints a notice that says so; the notice does not change
// the exit status. It does refuse to run on a PHP newer than it supports,
// which is deliberate: `setUnsupportedPhpVersionAllowed()` is not called.

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    // `build/` is ignored by Git and holds what the tools write. The
    // benchmark comparison keeps another commit's `src/` there
    // (`build/base/src`), which is not this commit's code to check or fix.
    ->exclude(['vendor', 'build'])
    ->name('*.php')
    ->notName('*.blade.php');

return (new PhpCsFixer\Config())
    // For `declare_strict_types`, the one risky rule enabled: it changes how
    // a file that lacked the declaration converts scalar arguments.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PHP8x3Migration' => true,
        'declare_strict_types' => true,

        // Where the project differs from the preset.
        // Exactly one space around an operator, where the preset accepts
        // more. `=>` is left alone, so an aligned array stays as written.
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => ['=>' => null],
        ],
        // The preset removes extra blank lines between imports only.
        'no_extra_blank_lines' => [
            'tokens' => [
                'curly_brace_block',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'throw',
                'use',
            ],
        ],
        // The preset groups imports by kind and leaves each group in the
        // order written; here each group is alphabetical as well.
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],

        // What the preset does not cover.
        'blank_line_before_statement' => [
            'statements' => ['return'],
        ],
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
            ],
        ],
        'include' => true,
        'increment_style' => true,
        'magic_constant_casing' => true,
        'native_function_casing' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_leading_namespace_whitespace' => true,
        'no_mixed_echo_print' => ['use' => 'echo'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_short_bool_cast' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_spaces_around_offset' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unused_imports' => true,
        'object_operator_without_whitespace' => true,
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_scalar' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'semicolon_after_instruction' => true,
        'single_line_comment_style' => [
            'comment_types' => ['hash'],
        ],
        'single_quote' => true,
        'space_after_semicolon' => true,
        'standardize_not_equals' => true,
        'trim_array_spaces' => true,
        'type_declaration_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
