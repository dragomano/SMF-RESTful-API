<?php

declare(strict_types=1);

/**
 * Mixes HasPanelChrome (palette switcher, dark-mode default, footer without
 * the upstream copyright/links) into HasPanelSwitcher — the trait every panel
 * layout uses — so generated layouts never need to be edited.
 *
 * Idempotent: a file that already uses the trait is left alone, and the older
 * HasPaletteSwitcher mixin (superseded by HasPanelChrome) is replaced.
 */

$path = __DIR__.'/../app/ApiAdmin/Layouts/HasPanelSwitcher.php';

if (! is_file($path)) {
    fwrite(STDERR, "patch-panel-chrome: {$path} not found\n");
    exit(1);
}

$contents = (string) file_get_contents($path);

if (str_contains($contents, 'use HasPanelChrome;')) {
    echo "patch-panel-chrome: already applied\n";
    exit(0);
}

// Replace the earlier palette-only mixin, if this volume has one.
if (str_contains($contents, '    use HasPaletteSwitcher;'."\n")) {
    $contents = str_replace('    use HasPaletteSwitcher;'."\n", '    use HasPanelChrome;'."\n", $contents);

    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "patch-panel-chrome: cannot write {$path}\n");
        exit(1);
    }

    echo "patch-panel-chrome: upgraded from HasPaletteSwitcher\n";
    exit(0);
}

$needle = "trait HasPanelSwitcher\n{\n";

if (! str_contains($contents, $needle)) {
    fwrite(STDERR, "patch-panel-chrome: trait body not found, skipped\n");
    exit(1);
}

$contents = str_replace($needle, $needle."    use HasPanelChrome;\n\n", $contents);

if (file_put_contents($path, $contents) === false) {
    fwrite(STDERR, "patch-panel-chrome: cannot write {$path}\n");
    exit(1);
}

echo "patch-panel-chrome: applied\n";
