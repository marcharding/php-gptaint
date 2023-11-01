<?php

namespace App\Service\CodeExtractor\NodeVisitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class UnusedMethodsRemoverVisitor extends NodeVisitorAbstract
{
    private mixed $unused;

    public function __construct($unused)
    {
        $this->unused = $unused;
    }

    public function leaveNode(Node $node)
    {
        foreach ($this->unused as $unused) {
            if ($node === $unused) {
                return NodeTraverser::REMOVE_NODE;
            }
        }

        return null;
    }
}
