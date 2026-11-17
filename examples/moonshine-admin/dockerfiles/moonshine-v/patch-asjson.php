<?php

declare(strict_types=1);

/**
 * Upstream MoonShine V fix: ServiceConfig::httpClient() calls ->asJson(),
 * which pins Content-Type: application/json onto every request, including
 * bodyless GET/HEAD. Some upstream APIs (e.g. the SMF RESTful API mod)
 * reject a JSON content-type with an empty body as a malformed payload.
 *
 * Laravel's HTTP client defaults to JSON body encoding anyway and Guzzle
 * adds the Content-Type header automatically when a JSON body is present,
 * so dropping asJson() keeps writes intact while fixing bodyless reads.
 * The patch is a no-op once upstream fixes the same lines.
 */

$root = rtrim(getenv('MOONSHINE_PATCH_ROOT') ?: dirname(__DIR__), '/\\');
$file = $root.'/app/ApiAdmin/ServiceConfig/ServiceConfig.php';

if (! is_file($file)) {
    fwrite(STDERR, "ServiceConfig.php not found\n");

    exit(1);
}

$contents = (string) file_get_contents($file);

$old = <<<'CODE'
            ->asJson()
            ->acceptJson()
CODE;

$new = <<<'CODE'
            // asJson() would pin Content-Type: application/json onto bodyless
            // GET/HEAD requests too; some upstream APIs reject that as a
            // malformed JSON payload. Guzzle adds the header automatically
            // whenever a JSON body is actually sent.
            ->acceptJson()
CODE;

if (! str_contains($contents, $old)) {
    if (str_contains($contents, '->acceptJson()') && ! str_contains($contents, '->asJson()')) {
        echo "ServiceConfig patch already applied.\n";
        exit(0);
    }

    fwrite(STDERR, "patch-asjson: incompatible ServiceConfig anchor in {$file}\n");

    exit(1);
}

if (file_put_contents($file, str_replace($old, $new, $contents)) === false) {
    fwrite(STDERR, "patch-asjson: cannot write {$file}\n");
    exit(1);
}

echo "ServiceConfig patched for bodyless JSON requests.\n";
