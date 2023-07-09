<?php

namespace App\Service\CodeExtractor\NodeVisitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class RemoveCodeAfterLineNumberNodeVisitor extends NodeVisitorAbstract
{

    private int $lineNumber;

    public function __construct($lineNumber)
    {
        $this->lineNumber = $lineNumber;
    }

    public function enterNode(Node $node)
    {
        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }

    public function leaveNode(Node $node)
    {
        if ($node->getStartLine() > $this->lineNumber) {
            return NodeTraverser::REMOVE_NODE;
        }
    }

}