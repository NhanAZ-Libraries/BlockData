<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
	->setRules([
		'array_syntax' => ['syntax' => 'short'],
		'no_trailing_whitespace' => true,
		'no_unused_imports' => true,
		'ordered_imports' => true,
		'single_blank_line_at_eof' => true,
	])
	->setFinder(Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests'])->name('*.php'));
