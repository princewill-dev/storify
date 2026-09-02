<?php

it('keeps controllers below the established size ceiling', function () {
    $root = dirname(__DIR__, 3).'/app/Http/Controllers';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $oversized = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $lines = count(file($file->getPathname()));
        if ($lines > 700) {
            $oversized[] = str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname()).": {$lines} lines";
        }
    }

    expect($oversized)->toBe([], implode(PHP_EOL, $oversized));
});
