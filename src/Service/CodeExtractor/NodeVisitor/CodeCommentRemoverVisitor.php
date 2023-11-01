<?php

namespace App\Service\CodeExtractor\NodeVisitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class CodeCommentRemoverVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        // Remove comments, if they are not GPT support comments
        if ($node instanceof Node\Stmt) {
            $comments = $node->getAttribute('comments');
            if ($comments) {
                $commentsAsText = array_reduce($comments, function ($commentsAsText, $comment) {
                    $commentsAsText .= $comment->getText();

                    return $commentsAsText;
                });
                if (str_contains($commentsAsText, NodeLineNumberMatchingVisitor::COMMENT_PREFIX)) {
                    return null;
                }
            }
            $node->setAttribute('comments', []);
        }

        return null;
    }
}
