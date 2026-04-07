<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/model',
        __DIR__ . '/scripts',
        __DIR__ . '/services',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'line_ending' => true,
    ])
    ->setFinder($finder);
