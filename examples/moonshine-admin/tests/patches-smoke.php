<?php

declare(strict_types=1);

$patchDir = dirname(__DIR__).'/dockerfiles/moonshine-v';
$temporary = sys_get_temp_dir().'/moonshine-patches-'.bin2hex(random_bytes(6));

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function putFixture(string $root, string $relative, string $contents): string
{
    $path = $root.'/'.$relative;
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Cannot create fixture directory: '.$directory);
    }
    file_put_contents($path, $contents);
    return $path;
}

function runPatch(string $script, array $arguments = []): array
{
    global $patchDir;
    $process = proc_open([PHP_BINARY, $patchDir.'/'.$script, ...$arguments], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start '.$script);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

try {
    mkdir($temporary);
    putenv('MOONSHINE_PATCH_ROOT='.$temporary);
    $layout = putFixture($temporary, 'stubs/api-admin/layout.stub', 'return $this->panelMenu();'.PHP_EOL);
    $hubLayout = putFixture($temporary, 'app/MoonShine/ApiAdmin/Layouts/AdminHubLayout.php', 'return $this->hubMenu();'.PHP_EOL);

    [$status] = runPatch('patch-layout-menu.php');
    ensure($status === 0 && str_contains((string) file_get_contents($layout), 'apiAdminMenu()'), 'Layout patch must apply.');
    ensure(file_get_contents($hubLayout) === 'return $this->hubMenu();'.PHP_EOL, 'The hub layout must not be patched as a service layout.');
    $firstResult = file_get_contents($layout);
    [$status] = runPatch('patch-layout-menu.php');
    ensure($status === 0 && file_get_contents($layout) === $firstResult, 'Layout patch must be idempotent.');

    file_put_contents($layout, 'return $this->customMenu();'.PHP_EOL);
    [$status, , $stderr] = runPatch('patch-layout-menu.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible target'), 'Unknown layout anchor must fail.');

    [$status] = runPatch('apply-patches.php', ['--unknown']);
    ensure($status === 2, 'Unknown runner options must be rejected.');

    $register = putFixture($temporary, 'app/ApiAdmin/MCP/Tools/RegisterServiceTool.php', "\$params['title'] ?? Str::title(\$name).' Admin'");
    $initialize = putFixture($temporary, 'app/ApiAdmin/MCP/Tools/InitializeLocalServiceTool.php', "Str::title(str_replace(['-', '_'], ' ', \$name)).' Admin'");
    [$status] = runPatch('patch-panel-title.php');
    ensure($status === 0, 'Panel-title patch must apply to both required sources.');
    [$status] = runPatch('patch-panel-title.php');
    ensure($status === 0, 'Already patched panel-title sources must be accepted.');
    file_put_contents($register, 'upstream title builder changed');
    [$status, , $stderr] = runPatch('patch-panel-title.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible expression'), 'Changed panel-title anchor must fail.');

    $dashboard = putFixture($temporary, 'app/MoonShine/Pages/Dashboard.php', 'return $this->title ?: \'Dashboard v2\';');
    [$status, , $stderr] = runPatch('patch-dashboard-title.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible title expression'), 'Changed dashboard anchor must fail.');

    $validator = putFixture($temporary, 'app/ApiAdmin/MCP/Generator/ResourceValidator.php', 'new Process([$customBinary, \'-l\', $file]);');
    [$status, , $stderr] = runPatch('patch-fpm-php-binary.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible ResourceValidator anchor'), 'Changed PHP lint anchor must fail.');

    $serviceConfig = putFixture($temporary, 'app/ApiAdmin/ServiceConfig/ServiceConfig.php', '->asJson()->acceptXml()');
    [$status, , $stderr] = runPatch('patch-asjson.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible ServiceConfig anchor'), 'Changed JSON client anchor must fail.');

    putFixture($temporary, 'resources/views/api-admin/components/panel-switcher.blade.php', '');
    putFixture($temporary, 'app/ApiAdmin/Layouts/HasPanelSwitcher.php', '');
    putFixture($temporary, 'app/MoonShine/ApiAdmin/Pages/AdminHubPage.php', "'Connect your first service'\n'Add service'\n");
    putFixture($temporary, 'resources/views/api-admin/components/admin-hub.blade.php', '');
    [$status, , $stderr] = runPatch('patch-hub-locale.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible anchor'), 'Partially patched hub file must fail.');

    $switcher = putFixture(
        $temporary,
        'resources/views/api-admin/components/panel-switcher.blade.php',
        "        title=\"Services changed upstream\"\nsearch-placeholder=\"{{ __('panel.hub.search') }}\"",
    );
    putFixture($temporary, 'app/ApiAdmin/Layouts/HasPanelSwitcher.php', "\$currentTitle = __('panel.hub.title');");
    putFixture(
        $temporary,
        'app/MoonShine/ApiAdmin/Pages/AdminHubPage.php',
        "\$this->title ?: __('panel.hub.title')\n__('panel.hub.connect_first')\n__('panel.hub.add_service')\n__('panel.hub.probe_first')\n__('panel.hub.register_another')\nSTATUS_OK => __('panel.hub.status.online')\nSTATUS_DEGRADED => __('panel.hub.status.degraded')\ndefault => __('panel.hub.status.offline')",
    );
    putFixture(
        $temporary,
        'resources/views/api-admin/components/admin-hub.blade.php',
        "<h2 class=\"api-admin-hub__title\">{{ __('panel.hub.select') }}</h2>\n<div class=\"api-admin-hub__subtitle\">{{ __('panel.hub.subtitle') }}</div>\n            {{ __('panel.hub.empty') }}\n                {{ __('panel.hub.add_service') }}\n",
    );
    [$status, , $stderr] = runPatch('patch-hub-locale.php');
    ensure($status !== 0 && str_contains($stderr, 'incompatible anchor'), 'Changed removable hub title must fail without its removal marker.');

    file_put_contents($switcher, "        {{-- Hub link is provided in the sidebar header. --}}\nsearch-placeholder=\"{{ __('panel.hub.search') }}\"");
    [$status] = runPatch('patch-hub-locale.php');
    ensure($status === 0, 'A hub title with the explicit removal marker must be accepted.');

    $entrypoint = (string) file_get_contents($patchDir.'/gen-smf-resources.php');
    $generator = (string) file_get_contents($patchDir.'/gen-smf-resources-library.php');
    ensure(str_contains($entrypoint, 'gen-smf-resources-library.php'), 'Seed entry point must load the generator.');
    ensure(str_contains($generator, 'SMF_FORUM_API_KEY'), 'The forum probe must read its API key from the environment.');
    ensure(! str_contains($entrypoint.$generator, 'smf-dev-key-0001'), 'API credentials must not be embedded in the generator.');

    echo "MoonShine patch smoke checks passed\n";
} finally {
    putenv('MOONSHINE_PATCH_ROOT');
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
}
