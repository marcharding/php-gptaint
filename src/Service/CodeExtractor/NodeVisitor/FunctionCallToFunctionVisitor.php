<?php

namespace App\Service\CodeExtractor\NodeVisitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class FunctionCallToFunctionVisitor extends NodeVisitorAbstract
{
    private Node\Expr\FuncCall $funcCall;
    private ?Node\Stmt\Function_ $function = null;

    public function __construct(Node\Expr\FuncCall $funcCall)
    {
        $this->funcCall = $funcCall;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Function_) {
            // Check if the class contains the method definition
            if ($node->name->name === $this->funcCall->name->parts[0]) {
                $this->function = $node;
            }
        }
    }

    public function getFunction(): ?Node\Stmt\Function_
    {
        return $this->function;
    }
}
