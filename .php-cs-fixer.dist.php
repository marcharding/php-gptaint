<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'data'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'yoda_style' => false,
        'increment_style' => false,
        'empty_loop_body' => false,
    ])
    ->setFinder($finder)
;
