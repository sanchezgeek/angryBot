<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ArrayNotation\ArraySyntaxFixer;
use PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer;
use PhpCsFixer\Fixer\FunctionNotation\NativeFunctionInvocationFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->paths([__DIR__ . '/src', __DIR__ . '/tests']);

    $ecsConfig->ruleWithConfiguration(ArraySyntaxFixer::class, [
        'syntax' => 'short',
    ]);

    $ecsConfig->services()->set(NativeFunctionInvocationFixer::class)->call('configure', [[
        'include' => [NativeFunctionInvocationFixer::SET_INTERNAL],
    ]]);

    $ecsConfig->services()->set(TrailingCommaInMultilineFixer::class)->call('configure', [[
        'elements' => [
            TrailingCommaInMultilineFixer::ELEMENTS_ARRAYS,
            TrailingCommaInMultilineFixer::ELEMENTS_ARGUMENTS,
            TrailingCommaInMultilineFixer::ELEMENTS_PARAMETERS,
        ],
    ]]);

    $ecsConfig->sets([
         SetList::CLEAN_CODE,
         SetList::PSR_12,
    ]);
};
