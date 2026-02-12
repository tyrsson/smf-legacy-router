<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$year = date('Y');

if ($year !== '2026') {
    $year = '2026-' . $year;
}

$packageName = (function (): string {
    $composerData = json_decode(file_get_contents('./composer.json'), true, 2);
    $totalLength  = strlen($composerData['name']);
    $slashPos     = strpos($composerData['name'], '/');
    return substr($composerData['name'], $slashPos + 1, $totalLength - $slashPos - 1);
})();

$fileHeader = <<<HEADER
    This file is part of the {$packageName} package.

    Copyright (c) {$year} Joey Smith <jsmith@webinertia.net>
    and contributors.

    For the full copyright and license information, please view the LICENSE
    file that was distributed with this source code.
    HEADER;

$rules = require 'vendor/webware/coding-standard/src/ruleset.php';

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect()) // @TODO 4.0 no need to call this manually
    ->setRiskyAllowed(true)
    ->setRules(
        array_merge(
            $rules,
            [
                'header_comment' => [
                    'header'   => $fileHeader,
                    'location' => 'after_declare_strict',
                    'separate' => 'both',
                ],
            ],
        ),
    )
    // 💡 by default, Fixer looks for `*.php` files excluding `./vendor/` - here, you can groom this config
    ->setFinder(
        (new Finder())
            // 💡 root folder to check
            ->in(__DIR__)
            // 💡 additional files, eg bin entry file
            // ->append([__DIR__.'/bin-entry-file'])
            // 💡 folders to exclude, if any
            // ->exclude([/* ... */])
            // 💡 path patterns to exclude, if any
            // ->notPath([/* ... */])
            // 💡 extra configs
            // ->ignoreDotFiles(false) // true by default in v3, false in v4 or future mode
            // ->ignoreVCS(true) // true by default
    );
