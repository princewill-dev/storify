<?php

it('uses business terminology everywhere, with no legacy exceptions', function () {
    $projectRoot = dirname(__DIR__, 3);
    $roots = [
        $projectRoot.'/app',
        $projectRoot.'/routes',
        $projectRoot.'/resources/views',
    ];

    $violations = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            $relativePath = str_replace($projectRoot.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

            foreach ($lines as $lineNumber => $line) {
                if (! preg_match('/\bvendor(?:s)?\b|Vendor[A-Z]|vendor_[a-z]/i', $line)) {
                    continue;
                }

                // The legacy Blade application, its route files, the mail shims
                // and the third-party asset directory are all gone, so this
                // rule no longer carries any carve-outs. A single mention
                // anywhere in app/, routes/ or resources/views/ now fails.
                $violations[] = sprintf('%s:%d: %s', $relativePath, $lineNumber + 1, trim($line));
            }
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});
