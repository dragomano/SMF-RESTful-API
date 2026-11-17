<?php

declare(strict_types=1);

/**
 * Points panel layouts at HasPanelChrome::apiAdminMenu(), which applies the
 * menu order declared in HasPanelChrome::MENU_ORDER and inserts the divider.
 *
 * Two kinds of files are handled:
 *  - stubs/api-admin/layout.stub — the template every new service layout is
 *    generated from (present in the image, patched at build time);
 *  - app/MoonShine/ApiAdmin/Layouts/*.php — layouts already generated into the
 *    app_state volume (patched when this script runs inside the container).
 *
 * Idempotent: files that already call apiAdminMenu() are skipped.
 */

$root = rtrim(getenv('MOONSHINE_PATCH_ROOT') ?: dirname(__DIR__), '/\\');
$stub = $root.'/stubs/api-admin/layout.stub';
$generated = array_values(array_filter(
	glob($root.'/app/MoonShine/ApiAdmin/Layouts/*.php') ?: [],
	static fn (string $path): bool => basename($path) !== 'AdminHubLayout.php',
));
$targets = [$stub, ...$generated];

$search = 'return $this->panelMenu();';
$replace = 'return $this->apiAdminMenu();';

$failed = false;
$changed = 0;

foreach ($targets as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, 'patch-layout-menu: required target missing: '.$path."\n");
        $failed = true;
        continue;
    }

    $contents = (string) file_get_contents($path);

    $hasOld = str_contains($contents, $search);
    $hasNew = str_contains($contents, $replace);

    if ($hasOld && $hasNew) {
        fwrite(STDERR, 'patch-layout-menu: mixed old and patched calls in '.$path."\n");
        $failed = true;
        continue;
    }

    if ($hasNew) {
        echo 'patch-layout-menu: '.$path." — already applied\n";
        continue;
    }

    if (! $hasOld) {
        fwrite(STDERR, 'patch-layout-menu: incompatible target '.$path.' (expected '.$search.' or '.$replace.")\n");
        $failed = true;
        continue;
    }

    if (file_put_contents($path, str_replace($search, $replace, $contents)) === false) {
        fwrite(STDERR, 'patch-layout-menu: cannot write '.$path."\n");
        exit(1);
    }

    echo 'patch-layout-menu: '.$path."\n";
    ++$changed;
}

echo "patch-layout-menu: {$changed} file(s) updated\n";
exit($failed ? 1 : 0);
