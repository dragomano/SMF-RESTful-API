<?php

declare(strict_types=1);

// One-off: the panel title stored by the service wizard keeps the casing the
// old generator produced ("Smf Forum Admin"). Rewrites generated panel titles
// through App\ApiAdmin\Support\PanelTitle, which is what new services use.
//
// Usage (inside the container): php docker/fix-panel-titles.php

require __DIR__.'/../vendor/autoload.php';

$path = __DIR__.'/../storage/api-admin/generated/panels.php';

if (! is_file($path)) {
    fwrite(STDERR, "fix-panel-titles: {$path} not found (no services generated yet)\n");
    exit(0);
}

$panels = include $path;

if (! is_array($panels)) {
    fwrite(STDERR, "fix-panel-titles: unexpected panels file\n");
    exit(1);
}

$changed = 0;

foreach ($panels as $key => $panel) {
    if (! is_array($panel) || ! isset($panel['title']) || ! is_string($panel['title'])) {
        continue;
    }

    // Derive the service name from the panel key ("smf_forum-admin").
    $service = str_ends_with($key, '-admin') ? substr($key, 0, -6) : $key;

    $legacyTitles = [
        Illuminate\Support\Str::title($service).' Admin',
        Illuminate\Support\Str::title(str_replace(['-', '_'], ' ', $service)).' Admin',
    ];
    $expected = App\ApiAdmin\Support\PanelTitle::fromServiceName($service);

    // This now runs on startup; leave explicitly customized titles alone.
    if (in_array($panel['title'], $legacyTitles, true) && $panel['title'] !== $expected) {
        $panels[$key]['title'] = $expected;
        ++$changed;
    }
}

if ($changed === 0) {
    echo "fix-panel-titles: nothing to do\n";
    exit(0);
}

$export = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($panels, true).";\n";

if (file_put_contents($path, $export) === false) {
    fwrite(STDERR, "fix-panel-titles: cannot write {$path}\n");
    exit(1);
}

echo "fix-panel-titles: {$changed} panel title(s) updated\n";
