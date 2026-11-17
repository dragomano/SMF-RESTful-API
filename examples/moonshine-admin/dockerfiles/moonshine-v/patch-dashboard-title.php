<?php

declare(strict_types=1);

/**
 * The default dashboard page hard-codes its title, so it stays "Dashboard"
 * even though `moonshine::ui.dashboard` is translated. Route the title through
 * the translator: "Dashboard" / "Дашборд".
 *
 * Idempotent: a second run is a no-op.
 */

$root = rtrim(getenv('MOONSHINE_PATCH_ROOT') ?: dirname(__DIR__), '/\\');
$path = $root.'/app/MoonShine/Pages/Dashboard.php';

if (! is_file($path)) {
    fwrite(STDERR, "patch-dashboard-title: {$path} not found\n");
    exit(1);
}

$contents = (string) file_get_contents($path);

if (str_contains($contents, $new = "return \$this->title ?: __('moonshine::ui.dashboard');")) {
    echo "patch-dashboard-title: already applied\n";
    exit(0);
}

$old = "return \$this->title ?: 'Dashboard';";
if (! str_contains($contents, $old)) {
    fwrite(STDERR, "patch-dashboard-title: incompatible title expression in {$path}\n");
    exit(1);
}

if (file_put_contents($path, str_replace($old, $new, $contents)) === false) {
    fwrite(STDERR, "patch-dashboard-title: cannot write {$path}\n");
    exit(1);
}

echo "patch-dashboard-title: applied\n";
