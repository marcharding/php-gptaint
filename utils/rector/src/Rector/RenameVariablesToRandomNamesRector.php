<?php

declare(strict_types=1);

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Utils\Rector\Tests\Rector\RenameVariablesToRandomNamesRector\RenameVariablesToRandomNamesRectorTest
 */
final class RenameVariablesToRandomNamesRector extends AbstractRector
{
    private mixed $variableNames;
    private mixed $variableCounter = 0;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('// @todo fill the description', [
            new CodeSample(
                <<<'CODE_SAMPLE'
// @todo fill code before
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
// @todo fill code after
CODE_SAMPLE
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Variable::class];
    }

    public function refactor(Node $node): ?Node
    {
        $globalVariables = [
            'GLOBALS',
            '_SERVER',
            '_GET',
            '_POST',
            '_FILES',
            '_COOKIE',
            '_SESSION',
            '_REQUEST',
            '_ENV',
        ];

        if (in_array($node->name, $globalVariables)) {
            return null;
        }

        $originalName = $node->name;
        if (!isset($this->variableNames[$originalName])) {
            $this->variableNames[$originalName] = $this->generateRandomName();
        }
        $newName = $this->variableNames[$originalName];
        $node->setAttribute('name', $newName);
        $node->name = $newName;

        return $node;
    }

    private function generateRandomName(): string
    {
        $this->variableCounter++;

        return 'var'.$this->variableCounter;

        return 'var_'.bin2hex(random_bytes(4));
    }
}
