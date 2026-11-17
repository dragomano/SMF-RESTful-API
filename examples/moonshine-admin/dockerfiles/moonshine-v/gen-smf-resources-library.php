<?php

declare(strict_types=1);

/**
 * Bootstrap the SMF service and generate its resources using the MoonShine stubs.
 * Page behavior lives in the stubs and SmfPageFields; upstream patches are separate.
 *
 * @param object $server An MCP server with an executeTool() method.
 * @param (callable(): mixed)|null $probe
 */
function generateSmfResources(
    object $server,
    ?callable $probe = null,
    ?string $appRoot = null,
    int $maxAttempts = 30,
): void {
    $appRoot ??= dirname(__DIR__);
    $probe ??= static fn (): string|false => @file_get_contents('http://nginx:8081/api/v1/members', false, stream_context_create([
        'http' => [
            'header' => 'X-API-Key: ' . getenv('SMF_FORUM_API_KEY'),
            'ignore_errors' => true,
            'timeout' => 3,
        ],
    ]));

    // Do not alter panel state until the forum API is available.
    $probeResult = false;

    for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
        $probeResult = $probe();

        if ($probeResult !== false) {
            break;
        }

        if ($attempt + 1 < $maxAttempts) {
            sleep(1);
        }
    }

    if ($probeResult === false) {
        echo "forum API unreachable — panel seed skipped (use the \"Add service\" wizard)
";

        return;
    }

    $registration = $server->executeTool('register_service', [
        'name' => 'smf_forum',
        'base_url' => 'http://nginx:8081/api/v1',
        'auth_type' => 'header',
        'auth_header' => 'X-API-Key',
        'health_path' => '/members',
        'response_format' => 'laravel',
        'panel' => 'smf-forum-admin',
    ]);

    echo sprintf("register: smf_forum — success=%s\n", var_export($registration['success'] ?? false, true));

    if (($registration['success'] ?? false) !== true) {
        echo json_encode($registration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }

    $payloads = [
        [
            'service' => 'smf_forum',
            'name' => 'category',
            'endpoint' => '/categories',
            'title' => 'Categories',
            'search' => ['name'],
            'fields' => [
                ['column' => 'id', 'label' => 'Id', 'type' => 'id', 'hide_on' => ['form']],
                ['column' => 'name', 'label' => 'Name', 'type' => 'text', 'methods' => ['required']],
                ['column' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['column' => 'order', 'label' => 'Order', 'type' => 'number'],
            ],
        ],
        [
            'service' => 'smf_forum',
            'name' => 'board',
            'endpoint' => '/boards',
            'title' => 'Boards',
            'search' => ['name'],
            'fields' => [
                ['column' => 'id', 'label' => 'Id', 'type' => 'id', 'hide_on' => ['form']],
                ['column' => 'category', 'label' => 'Category id', 'type' => 'number', 'methods' => ['required']],
                ['column' => 'parent', 'label' => 'Parent board id', 'type' => 'number'],
                ['column' => 'name', 'label' => 'Name', 'type' => 'text', 'methods' => ['required']],
                ['column' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['column' => 'num_topics', 'label' => 'Topics', 'type' => 'number', 'hide_on' => ['form']],
                ['column' => 'num_posts', 'label' => 'Posts', 'type' => 'number', 'hide_on' => ['form']],
                ['column' => 'order', 'label' => 'Order', 'type' => 'number'],
            ],
        ],
        [
            'service' => 'smf_forum',
            'name' => 'topic',
            'endpoint' => '/topics',
            'title' => 'Topics',
            'search' => ['subject'],
            'fields' => [
                ['column' => 'id', 'label' => 'Id', 'type' => 'id', 'hide_on' => ['form']],
                ['column' => 'board', 'label' => 'Board id', 'type' => 'number', 'methods' => ['required']],
                ['column' => 'subject', 'label' => 'Subject', 'type' => 'text', 'methods' => ['required']],
                ['column' => 'body', 'label' => 'Body', 'type' => 'textarea'],
                ['column' => 'poster_time', 'label' => 'Posted at', 'type' => 'number', 'hide_on' => ['form']],
                ['column' => 'num_replies', 'label' => 'Replies', 'type' => 'number', 'hide_on' => ['form']],
                ['column' => 'num_views', 'label' => 'Views', 'type' => 'number', 'hide_on' => ['form']],
                ['column' => 'locked', 'label' => 'Locked', 'type' => 'switcher'],
                ['column' => 'is_sticky', 'label' => 'Sticky', 'type' => 'switcher'],
                ['column' => 'approved', 'label' => 'Approved', 'type' => 'number', 'hide_on' => ['form']],
            ],
        ],
        [
            'service' => 'smf_forum',
            'name' => 'group',
            'endpoint' => '/groups',
            'title' => 'Groups',
            'search' => ['name'],
            'fields' => [
                ['column' => 'id', 'label' => 'Id', 'type' => 'id', 'hide_on' => ['form']],
                ['column' => 'name', 'label' => 'Name', 'type' => 'text', 'methods' => ['required']],
                ['column' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['column' => 'type', 'label' => 'Type', 'type' => 'number'],
                ['column' => 'min_posts', 'label' => 'Min posts', 'type' => 'number'],
                ['column' => 'max_messages', 'label' => 'Max messages', 'type' => 'number'],
                ['column' => 'color', 'label' => 'Color', 'type' => 'color'],
                ['column' => 'icons', 'label' => 'Icons', 'type' => 'text', 'hide_on' => ['index', 'form']],
                ['column' => 'hidden', 'label' => 'Hidden', 'type' => 'switcher', 'hide_on' => ['form']],
                ['column' => 'parent', 'label' => 'Parent group id', 'type' => 'number', 'hide_on' => ['form']],
                ['column' => 'tfa_required', 'label' => '2FA required', 'type' => 'checkbox', 'hide_on' => ['form']],
            ],
        ],
        [
            'service' => 'smf_forum',
            'name' => 'member',
            'endpoint' => '/members',
            'title' => 'Members',
            'search' => ['username', 'name'],
            'fields' => [
                ['column' => 'id', 'label' => 'Id', 'type' => 'id', 'hide_on' => ['form']],
                ['column' => 'username', 'label' => 'Username', 'type' => 'text'],
                ['column' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['column' => 'posts', 'label' => 'Posts', 'type' => 'text'],
                ['column' => 'date_registered', 'label' => 'Date Registered', 'type' => 'text', 'hide_on' => ['form']],
                ['column' => 'last_login', 'label' => 'Last Login', 'type' => 'text', 'hide_on' => ['form']],
                ['column' => 'primary_group', 'label' => 'Primary Group', 'type' => 'text'],
                ['column' => 'post_group', 'label' => 'Post Group', 'type' => 'text'],
            ],
        ],
    ];

    $resourceDir = $appRoot.'/app/MoonShine/ApiAdmin/Resources/SmfForum';

    foreach ($payloads as $payload) {
        $payload['panel'] = 'smf-forum-admin';
        $ucName = ucfirst($payload['name']);
        $classFile = $resourceDir."/{$ucName}/{$ucName}Resource.php";

        if (is_file($classFile)) {
            echo sprintf("%s: already exists, skipping creation\n", $payload['name']);

            continue;
        }

        $result = $server->executeTool('create_resource', $payload);
        echo sprintf("%s: success=%s file=%s\n", $payload['name'], var_export($result['success'] ?? false, true), $result['file'] ?? 'n/a');

        if (($result['success'] ?? false) !== true) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        }
    }

    // Migrate existing volumes from the old underscore panel prefix.
    $migrationFiles = [
        $appRoot.'/storage/api-admin/generated/panels.php',
        $appRoot.'/storage/api-admin/generated/services.php',
        $appRoot.'/app/MoonShine/ApiAdmin/Configs/SmfForumConfig.php',
        $appRoot.'/storage/runtime/configs/SmfForumConfig.php',
    ];

    foreach (glob($appRoot.'/storage/runtime/resources/SmfForum/*/*.php') ?: [] as $runtimeResource) {
        $migrationFiles[] = $runtimeResource;
    }

    foreach ($migrationFiles as $configFile) {
        if (! is_file($configFile) || ! str_contains((string) file_get_contents($configFile), 'smf_forum-admin')) {
            continue;
        }

        file_put_contents($configFile, str_replace('smf_forum-admin', 'smf-forum-admin', (string) file_get_contents($configFile)));
        echo sprintf("register: panel prefix migrated in %s\n", basename(dirname($configFile)).'/'.basename($configFile));
    }
}
