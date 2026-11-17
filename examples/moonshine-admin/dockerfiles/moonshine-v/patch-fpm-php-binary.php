<?php

declare(strict_types=1);

/**
 * Upstream MoonShine V fix for fpm contexts: ResourceValidator runs `php -l`
 * through PHP_BINARY, which under the php-fpm SAPI resolves to the php-fpm
 * binary itself, so lint always "fails" with the fpm usage message.
 * Switch to the CLI binary (PHP_BINDIR/php) when running under fpm.
 * The patch is a no-op once upstream fixes the same line.
 */

$root = rtrim(getenv('MOONSHINE_PATCH_ROOT') ?: dirname(__DIR__), '/\\');
$file = $root.'/app/ApiAdmin/MCP/Generator/ResourceValidator.php';

if (! is_file($file)) {
    fwrite(STDERR, "ResourceValidator.php not found\n");

    exit(1);
}

$contents = (string) file_get_contents($file);

$old = <<<'CODE'
            $process = new Process([PHP_BINARY, '-l', $file]);
CODE;

$new = <<<'CODE'
            // PHP_BINARY under the php-fpm SAPI is the fpm binary, not the CLI.
            $phpCli = PHP_SAPI === 'fpm-fcgi' ? PHP_BINDIR.'/php' : PHP_BINARY;
            $process = new Process([$phpCli, '-l', $file]);
CODE;

if (! str_contains($contents, $old)) {
    if (str_contains($contents, '$phpCli = PHP_SAPI') && str_contains($contents, 'new Process([$phpCli, \'-l\', $file])')) {
        echo "ResourceValidator patch already applied.\n";
        exit(0);
    }

    fwrite(STDERR, "patch-fpm-php-binary: incompatible ResourceValidator anchor in {$file}\n");

    exit(1);
}

if (file_put_contents($file, str_replace($old, $new, $contents)) === false) {
    fwrite(STDERR, "patch-fpm-php-binary: cannot write {$file}\n");
    exit(1);
}

echo "ResourceValidator patched for fpm php -l.\n";
