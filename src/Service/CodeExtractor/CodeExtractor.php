<?php

namespace App\Service\CodeExtractor;

use App\Service\CodeExtractor\NodeVisitor\CodeCommentRemoverVisitor;
use App\Service\CodeExtractor\NodeVisitor\FunctionCallToFunctionVisitor;
use App\Service\CodeExtractor\NodeVisitor\MethodCallToClassMethodVisitor;
use App\Service\CodeExtractor\NodeVisitor\NodeLineNumberMatchingVisitor;
use App\Service\CodeExtractor\NodeVisitor\RemoveCodeAfterLineNumberNodeVisitor;
use App\Service\CodeExtractor\NodeVisitor\UnusedMethodsRemoverVisitor;
use PhpParser;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

class CodeExtractor
{
    public function extractCodeLeadingToLine($filePath, $targetLineNumber)
    {
        $code = file_get_contents($filePath);

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $ast = $parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NodeConnectingVisitor());

        $visitor = new NodeLineNumberMatchingVisitor($targetLineNumber);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        $matchingNode = $visitor->getNode();

        // collect all nodes which are need to reach the node at the code line (e.g. function, methods, classes, etc.)

        // when the line is inside a class / classmethod, determine the class it belongs to
        $currentClass = null;
        $currentClassMethod = null;
        while ($matchingNode && $matchingNode->hasAttribute('parent')) {
            $matchingNode = $matchingNode->getAttribute('parent');
            if ($matchingNode instanceof ClassMethod) {
                $currentClassMethod = $matchingNode;
                $currentClass = $matchingNode->getAttribute('parent');
                break;
            }
        }

        // the same but for a function
        $currentFunction = null;
        $matchingNode = $visitor->getNode();
        while ($matchingNode && $matchingNode->hasAttribute('parent')) {
            $matchingNode = $matchingNode->getAttribute('parent');
            if ($matchingNode instanceof Function_) {
                $currentFunction = $matchingNode;
                break;
            }
        }

        if (!$currentFunction) {
            // the same but for a function
            $currentFunctionCall = null;
            $matchingNode = $visitor->getNode();
            while ($matchingNode && $matchingNode->hasAttribute('parent')) {
                $matchingNode = $matchingNode->getAttribute('parent');
                if ($matchingNode instanceof Node\Expr\FuncCall) {
                    $currentFunctionCall = $matchingNode;
                    break;
                }
            }

            if ($currentFunctionCall instanceof Node\Expr\FuncCall) {
                $traverser = new NodeTraverser();
                $visitor = new FunctionCallToFunctionVisitor($matchingNode);
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                $currentFunction = $visitor->getFunction();
            }
        }
        // collect nodes that can be removed

        $nodeFinder = new NodeFinder();

        if ($currentClassMethod) {
            $calledClassMethods = [];
            if ($currentClassMethod->stmts) {
                $this->findCalledMethodsRecursive($ast, $currentClassMethod->stmts, $calledClassMethods);
            }
        }

        // remove all unused methods.
        $matchingNodes = [];
        if ($currentClassMethod) {
            $matchingNodeResults = $nodeFinder->find($ast, function (Node $node) use ($currentClassMethod, $calledClassMethods) {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    if ($node === $currentClassMethod) {
                        return false;
                    }
                    foreach ($calledClassMethods as $calledClassMethod) {
                        if ($node === $calledClassMethod) {
                            return false;
                        }
                    }

                    return true;
                }

                return null;
            });
            $matchingNodes = array_merge($matchingNodes, $matchingNodeResults);
        }

        $matchingNodeResults = $nodeFinder->find($ast, function (Node $node) use ($currentClass) {
            return $node instanceof Node\Stmt\Class_ && $node !== $currentClass;
        });
        $matchingNodes = array_merge($matchingNodes, $matchingNodeResults);

        $matchingNodeResults = $nodeFinder->find($ast, function (Node $node) use ($currentFunction) {
            return $node instanceof Node\Stmt\Function_ && $node !== $currentFunction;
        });
        $matchingNodes = array_merge($matchingNodes, $matchingNodeResults);

        // First, lets remove all comments
        $traverser->addVisitor(new CodeCommentRemoverVisitor());
        $ast = $traverser->traverse($ast);

        // Next remove unused code parts
        $traverser->addVisitor(new UnusedMethodsRemoverVisitor($matchingNodes));
        $ast = $traverser->traverse($ast);

        // Remove everything after the given line
        $traverser->addVisitor(new RemoveCodeAfterLineNumberNodeVisitor($targetLineNumber));
        $ast = $traverser->traverse($ast);
        // $printer = new Standard(['preserveComments' => true]);
        $prettyPrinter = new PrettyPrinter\Standard(['preserveComments' => true]);
        $modifiedCode = $prettyPrinter->prettyPrintFile($ast);

        return $modifiedCode;
    }

    public function findCalledMethodsRecursive($ast, array $stmts, array &$calledMethods)
    {
        foreach ($stmts as $stmt) {
            $expr = $stmt instanceof PhpParser\Node\Stmt\Expression ? $stmt->expr : $stmt;

            if ($expr instanceof PhpParser\Node\Stmt\Switch_) {
                if ($stmt->cond instanceof PhpParser\Node\Expr\MethodCall || $stmt->cond instanceof PhpParser\Node\Expr\FuncCall) {
                    $this->findCalledMethodsRecursive($ast, [$stmt->cond], $calledMethods);
                }
                foreach ($expr->cases as $case) {
                    $this->findCalledMethodsRecursive($ast, $case->stmts, $calledMethods);
                }
            } elseif (
                $expr instanceof If_
                || $expr instanceof ElseIf_
                || $expr instanceof PhpParser\Node\Stmt\Else_
                || $expr instanceof PhpParser\Node\Stmt\While_
                || $expr instanceof PhpParser\Node\Stmt\Do_
                || $expr instanceof PhpParser\Node\Stmt\For_
                || $expr instanceof PhpParser\Node\Stmt\Foreach_
                || $expr instanceof PhpParser\Node\Stmt\TryCatch
            ) {
                $this->findCalledMethodsRecursive($ast, $expr->stmts ?? [], $calledMethods);
                $this->findCalledMethodsRecursive($ast, [$stmt->cond ?? null] ?? [], $calledMethods);
            // Recursively traverse the AST of the nested statements
            } elseif ($expr instanceof PhpParser\Node\Expr\FuncCall) {
            } elseif ($expr instanceof PhpParser\Node\Expr\MethodCall) {
                if ($expr->var instanceof PhpParser\Node\Expr\Variable && $expr->var->name === 'this') {
                    $traverser = new NodeTraverser();
                    $visitor = new MethodCallToClassMethodVisitor($expr);
                    $traverser->addVisitor($visitor);
                    $traverser->traverse($ast);

                    // $calledMethodName = $expr->name->name;
                    $classMethod = $visitor->getClassMethod();

                    // only add a method once, hopefully fixes endless recursion in
                    // amazon-auto-links/include/library/apf/factory/admin_page/_model/AdminPageFramework_ExportOptions.php:55:20
                    if (is_null($classMethod) || isset($calledMethods[spl_object_id($classMethod)])) {
                        continue;
                    }
                    $calledMethods[spl_object_id($classMethod)] = $classMethod;

                    $calledMethods[] = $classMethod;

                    // Recursively traverse the AST of the newly found method
                    if (isset($classMethod->stmts)) {
                        $this->findCalledMethodsRecursive($ast, $classMethod->stmts, $calledMethods);
                    }
                }
            } elseif ($expr instanceof PhpParser\Node\Expr\Assign) {
                $this->findCalledMethodsRecursive($ast, [$expr->expr], $calledMethods);
            } elseif ($expr instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
                $this->findCalledMethodsRecursive($ast, [$expr->left, $expr->right], $calledMethods);
            }
        }
    }
}
