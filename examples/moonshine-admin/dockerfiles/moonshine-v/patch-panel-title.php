<?php

declare(strict_types=1);

/**
 * Replaces the service-name → panel-title expression in the two MCP tools that
 * register a service. `Str::title('smf_forum').' Admin'` produced
 * "Smf Forum Admin"; App\ApiAdmin\Support\PanelTitle keeps short
 * abbreviations upper-case instead ("SMF Forum Admin").
 *
 * Idempotent: files that already call the helper are skipped.
 */

$root = rtrim(getenv('MOONSHINE_PATCH_ROOT') ?: dirname(__DIR__), '/\\');
$replacements = [
    $root.'/app/ApiAdmin/MCP/Tools/RegisterServiceTool.php' => [
        "\$params['title'] ?? Str::title(\$name).' Admin'" => "\$params['title'] ?? \\App\\ApiAdmin\\Support\\PanelTitle::fromServiceName(\$name)",
    ],
    $root.'/app/ApiAdmin/MCP/Tools/InitializeLocalServiceTool.php' => [
        "Str::title(str_replace(['-', '_'], ' ', \$name)).' Admin'" => "\\App\\ApiAdmin\\Support\\PanelTitle::fromServiceName(\$name)",
    ],
];

$failed = false;

foreach ($replacements as $path => $pairs) {
    if (! is_file($path)) {
        fwrite(STDERR, "patch-panel-title: {$path} not found\n");
        $failed = true;

        continue;
    }

    $contents = (string) file_get_contents($path);
    $applied  = 0;

    foreach ($pairs as $search => $replace) {
        $hasOld = str_contains($contents, $search);
        $hasNew = str_contains($contents, $replace);

        if ($hasOld && $hasNew) {
            fwrite(STDERR, 'patch-panel-title: mixed old and patched expressions in '.$path."\n");
            $failed = true;

            continue;
        }

        if ($hasNew) {
            continue;
        }

        if (! $hasOld) {
            fwrite(STDERR, 'patch-panel-title: incompatible expression in '.$path.' — expected '.$search."\n");
            $failed = true;

            continue;
        }

        $contents = str_replace($search, $replace, $contents);
        ++$applied;
    }

    if ($applied === 0 && ! $failed) {
        echo sprintf("patch-panel-title: %s — already applied%s", basename($path), PHP_EOL);

        continue;
    }

    if ($applied === 0) {
        continue;
    }

    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "patch-panel-title: cannot write {$path}\n");
        $failed = true;

        continue;
    }

    echo sprintf("patch-panel-title: %s — title builder replaced%s", basename($path), PHP_EOL);
}

exit($failed ? 1 : 0);
