<?php

declare(strict_types=1);

/**
 * Localises the service hub: the panel switcher and the hub page itself ship
 * with hard-coded English strings ("Services", "Select a service", "Online"…),
 * and the switcher's dropdown also renders its `title` as a non-clickable
 * heading row inside the menu.
 *
 * Also removes that heading, because the hub is reachable through the
 * "All services" button in the sidebar header.
 *
 * Idempotent: files that already reference panel.hub are skipped.
 */

$root = rtrim(getenv('MOONSHINE_PATCH_ROOT') ?: dirname(__DIR__), '/\\');
$edits = [
    // Dropdown heading + search placeholder.
    $root.'/resources/views/api-admin/components/panel-switcher.blade.php' => [
        ["        title=\"Services\"\n", "        {{-- Hub link is provided in the sidebar header. --}}\n"],
        ['search-placeholder="Search service..."', 'search-placeholder="{{ __(\'panel.hub.search\') }}"'],
    ],

    // Switcher toggler label when the hub itself is open.
    $root.'/app/ApiAdmin/Layouts/HasPanelSwitcher.php' => [
        ["\$currentTitle = 'Services';", "\$currentTitle = __('panel.hub.title');"],
    ],

    // Hub page: page title, action card, health labels.
    $root.'/app/MoonShine/ApiAdmin/Pages/AdminHubPage.php' => [
        ["\$this->title ?: 'Services'", "\$this->title ?: __('panel.hub.title')"],
        ["'Connect your first service'", "__('panel.hub.connect_first')"],
        ["'Add service'", "__('panel.hub.add_service')"],
        ["'Probe an API and review the first generated resource'", "__('panel.hub.probe_first')"],
        ["'Register another upstream API'", "__('panel.hub.register_another')"],
        ["STATUS_OK => 'Online'", "STATUS_OK => __('panel.hub.status.online')"],
        ["STATUS_DEGRADED => 'Degraded'", "STATUS_DEGRADED => __('panel.hub.status.degraded')"],
        ["default => 'Offline'", "default => __('panel.hub.status.offline')"],
    ],

    // Hub view: headings and the empty state.
    $root.'/resources/views/api-admin/components/admin-hub.blade.php' => [
        [
            '<h2 class="api-admin-hub__title">Select a service</h2>',
            '<h2 class="api-admin-hub__title">{{ __(\'panel.hub.select\') }}</h2>',
        ],
        [
            '<div class="api-admin-hub__subtitle">Open a generated panel or connect another upstream API.</div>',
            '<div class="api-admin-hub__subtitle">{{ __(\'panel.hub.subtitle\') }}</div>',
        ],
        ["            No services are registered yet.\n", "            {{ __('panel.hub.empty') }}\n"],
        ["                Add service\n", "                {{ __('panel.hub.add_service') }}\n"],
    ],
];

$failed = false;

foreach ($edits as $path => $pairs) {
    if (! is_file($path)) {
        fwrite(STDERR, 'patch-hub-locale: missing '.$path."\n");
        $failed = true;

        continue;
    }

    $contents = (string) file_get_contents($path);
    $applied = 0;

    foreach ($pairs as [$search, $replace]) {
        if ($search === '') {
            continue;
        }

        if (str_contains($contents, $search)) {
            $contents = str_replace($search, $replace, $contents);
            ++$applied;
            continue;
        }

        if ($replace !== '' && str_contains($contents, $replace)) {
            continue;
        }

        fwrite(STDERR, 'patch-hub-locale: incompatible anchor in '.$path.' — '.str_replace("\n", '\\n', $search)."\n");
        $failed = true;
    }

    if ($applied === 0) {
        if (! $failed) {
            echo 'patch-hub-locale: '.basename($path)." — already applied\n";
        }

        continue;
    }

    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, 'patch-hub-locale: cannot write '.$path."\n");
        $failed = true;

        continue;
    }

    echo 'patch-hub-locale: '.basename($path)." — {$applied} string(s)\n";
}

exit($failed ? 1 : 0);
