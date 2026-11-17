<?php

declare(strict_types=1);

/**
 * Applies the MoonShine source patches in their required order. Resource
 * generation remains a separate lifecycle and is handled by gen-smf-resources.php.
 */

$arguments = array_slice($argv, 1);

if ($arguments !== [] && $arguments !== ['--runtime']) {
    fwrite(STDERR, "Usage: php docker/apply-patches.php [--runtime]\n");
    exit(2);
}

$runtime = $arguments === ['--runtime'];
$scripts = $runtime
    ? ['patch-locales.php', 'patch-layout-menu.php', 'patch-panel-chrome.php', 'patch-hub-locale.php', 'fix-panel-titles.php']
    : [
        'patch-fpm-php-binary.php',
        'patch-asjson.php',
        'patch-locales.php',
        'patch-panel-chrome.php',
        'patch-panel-title.php',
        'patch-layout-menu.php',
        'patch-dashboard-title.php',
        'patch-hub-locale.php',
    ];

foreach ($scripts as $script) {
    $command = [PHP_BINARY, __DIR__.'/'.$script];

    if ($runtime && $script === 'patch-locales.php') {
        $command[] = '/var/www/html/storage/runtime/moonshine.php';
    }

    $process = proc_open($command, [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ], $pipes);

    if (! is_resource($process)) {
        fwrite(STDERR, "apply-patches: unable to start {$script}\n");
        exit(1);
    }

    $status = proc_close($process);

    if ($status !== 0) {
        fwrite(STDERR, "apply-patches: failed at {$script}\n");
        exit($status);
    }
}
