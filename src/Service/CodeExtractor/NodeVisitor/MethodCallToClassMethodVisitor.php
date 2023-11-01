<?php

namespace App\Service\CodeExtractor\NodeVisitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class MethodCallToClassMethodVisitor extends NodeVisitorAbstract
{
    private Node\Expr\MethodCall $methodCall;
    private ?Node\Stmt\ClassMethod $classMethod = null;

    public function __construct(Node\Expr\MethodCall $methodCall)
    {
        $this->methodCall = $methodCall;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            // Check if the class contains the method definition
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->name === $this->methodCall->name->name) {
                    $this->classMethod = $stmt;
                    break;
                }
            }
        }
    }

    public function getClassMethod(): ?Node\Stmt\ClassMethod
    {
        return $this->classMethod;
    }
}
