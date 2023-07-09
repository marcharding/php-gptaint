<?php

namespace App\Service\CodeExtractor\NodeVisitor;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class NodeLineNumberMatchingVisitor extends NodeVisitorAbstract
{
    public const COMMENT_PREFIX = '// @GPT-SUPPORT:';
    private $maxDepth = 0;
    private $currentDepth = 0;
    private $deepestNode;
    private $lineNumber;
    private $node;

    public function __construct($lineNumber)
    {
        $this->lineNumber = $lineNumber;
    }

    public function enterNode(Node $node)
    {
        $this->currentDepth++;

        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        if ($startLine >= $this->lineNumber && $endLine <= $this->lineNumber) {

            if ($this->currentDepth > $this->maxDepth) {
                $this->maxDepth = $this->currentDepth;
                $node->setAttribute('comments', [
                    new Comment(sprintf("%s Possible taint after this comment", NodeLineNumberMatchingVisitor::COMMENT_PREFIX))
                ]);
                $this->node = $node;
            }
        }
    }

    public function leaveNode(Node $node)
    {
        $this->currentDepth--;
    }

    public function getNode()
    {
        return $this->node;
    }
}