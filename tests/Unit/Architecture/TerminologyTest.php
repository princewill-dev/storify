<?php

it('keeps legacy vendor terminology inside explicit compatibility boundaries', function () {
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

                $isLegacyMailShim = str_starts_with($relativePath, 'app/Mail/Vendor')
                    || $relativePath === 'app/Mail/AdminVendorCreated.php';
                $isLegacyRoute = in_array($relativePath, [
                    'routes/v1/vendor.php',
                    'routes/v1/admin_dashboard.php',
                    'routes/web.php',
                    'routes/v1/home.php',
                    'routes/v1/storefront.php',
                    'routes/api/v1/storefront_api.php',
                ], true);
                $isThirdPartyAssetPath = str_contains($line, 'vendor_files/')
                    || str_contains($line, '/assets/js/vendor/')
                    || str_contains($line, 'assets/js/vendor/');

                if (! $isLegacyMailShim && ! $isLegacyRoute && ! $isThirdPartyAssetPath) {
                    $violations[] = sprintf('%s:%d: %s', $relativePath, $lineNumber + 1, trim($line));
                }
            }
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});
