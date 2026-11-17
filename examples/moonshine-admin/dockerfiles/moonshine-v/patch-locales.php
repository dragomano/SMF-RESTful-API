<?php

declare(strict_types=1);

/**
 * Enables the EN/RU locale switcher by filling the 'locales' list in
 * config/moonshine.php.
 *
 * Used twice:
 *  - at build time (no arguments): patches the source config, so the seed
 *    baked into the image has locales for fresh installs;
 *  - at boot (moonshine-init passes the runtime config path): the runtime
 *    config lives in the app_state volume and may predate this setting,
 *    so the patch is applied idempotently on every start.
 */

$path = $argv[1] ?? dirname(__DIR__).'/config/moonshine.php';

if (! is_file($path)) {
    fwrite(STDERR, "Config file not found: {$path}\n");

    exit(1);
}

$contents = (string) file_get_contents($path);

$old = <<<'CODE'
    'locales' => [
        // en
    ],
CODE;

$new = <<<'CODE'
    'locales' => [
        'en' => 'English',
        'ru' => 'Русский',
    ],
CODE;

if (str_contains($contents, "'ru' => 'Русский'")) {
    echo "Locales already configured in {$path}\n";

    exit(0);
}

if (! str_contains($contents, $old)) {
    fwrite(STDERR, "Locales block not found in {$path} — update the patch.\n");

    exit(1);
}

if (file_put_contents($path, str_replace($old, $new, $contents)) === false) {
    fwrite(STDERR, "Unable to write locale config: {$path}\n");
    exit(1);
}

echo "Locales (en/ru) enabled in {$path}\n";
