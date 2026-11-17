<?php

declare(strict_types=1);

use Testo\Assert;
use Testo\Test;

require_once __DIR__ . '/../../examples/moonshine-admin/dockerfiles/moonshine-v/gen-smf-resources-library.php';

#[Test]
final class MoonshineResourceGeneratorTest
{
    public function generatesResourcesFromTemplates(): void
    {
        $root = $this->root();
        $server = $this->server($root);

        generateSmfResources($server, static fn (): string => 'ok', $root, 1);

        Assert::same(count($server->created), 5);
        Assert::same($server->calls[0], 'register_service');
        Assert::same($server->created, ['Category', 'Board', 'Topic', 'Group', 'Member']);
        Assert::same($server->payloads[0], ['register_service', [
            'name' => 'smf_forum',
            'base_url' => 'http://nginx:8081/api/v1',
            'auth_type' => 'header',
            'auth_header' => 'X-API-Key',
            'health_path' => '/members',
            'response_format' => 'laravel',
            'panel' => 'smf-forum-admin',
        ]]);
        Assert::same($server->payloads[1][0], 'create_resource');
        Assert::same($server->payloads[1][1]['name'], 'category');
        Assert::same($server->payloads[1][1]['endpoint'], '/categories');
        Assert::true(is_file($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/Topic/TopicResource.php'));
        $topic = file_get_contents($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/Topic/TopicResource.php');
        $page = file_get_contents($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/Topic/Pages/TopicIndexPage.php');

        Assert::true(str_contains($topic, "\$data['sticky'] = \$data['is_sticky'];"));
        Assert::true(str_contains($topic, "\$data['body'] = \$data['body_raw'];"));
        $board = file_get_contents($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/Board/BoardResource.php');
        Assert::true(str_contains($board, "unset(\$data['order']);"));
        Assert::true(str_contains($page, 'SmfPageFields::index'));
        Assert::true(! str_contains($page, "'Starter id'"));

        foreach ($this->snapshot($root) as $source) {
            Assert::true(! str_contains($source, '{{ '));
            Assert::true(token_get_all($source, TOKEN_PARSE) !== []);
        }

        $this->remove($root);
    }

    public function secondRunIsStableAndKeepsExistingResource(): void
    {
        $root = $this->root();
        $server = $this->server($root);
        generateSmfResources($server, static fn (): string => 'ok', $root, 1);
        $before = $this->snapshot($root);

        generateSmfResources($server, static fn (): string => 'ok', $root, 1);

        Assert::same($this->snapshot($root), $before);

        $resource = $root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/Topic/TopicResource.php';
        $userResource = "<?php\nclass TopicResource\n{\n    protected string \$title = 'Custom topics';\n}\n";
        file_put_contents($resource, $userResource);
        $topicPages = [
            'TopicIndexPage.php' => "<?php\nuse MoonShine\\UI\\Fields\\Number;\nuse MoonShine\\UI\\Fields\\Text;\nuse MoonShine\\UI\\Fields\\Textarea;\nclass TopicIndexPage\n{\n    public function fields(): array\n    {\n        return [\n            Number::make('Board id', 'board')->required(),\n            Text::make('Subject', 'subject'),\n            Number::make('Starter id', 'starter'),\n            Textarea::make('Body', 'body'),\n        ];\n    }\n}\n",
            'TopicDetailPage.php' => "<?php\nuse MoonShine\\UI\\Fields\\Number;\nuse MoonShine\\UI\\Fields\\Textarea;\nclass TopicDetailPage\n{\n    public function fields(): array\n    {\n        return [\n            Number::make('Board id', 'board')->required(),\n            Textarea::make('Body', 'body'),\n        ];\n    }\n}\n",
            'TopicFormPage.php' => "<?php\nuse MoonShine\\UI\\Fields\\Number;\nuse MoonShine\\UI\\Fields\\Textarea;\nclass TopicFormPage\n{\n    public function fields(): array\n    {\n        return [\n            Number::make('Board id', 'board')->required(),\n            Textarea::make('Body', 'body'),\n        ];\n    }\n}\n",
        ];
        $topicPagesDir = $root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/Topic/Pages';

        foreach ($topicPages as $filename => $contents) {
            file_put_contents($topicPagesDir . '/' . $filename, $contents);
        }

        $beforeUserRun = $this->snapshot($root);

        generateSmfResources($server, static fn (): string => 'ok', $root, 1);

        Assert::same(file_get_contents($resource), $userResource);
        Assert::same($this->snapshot($root), $beforeUserRun);
        $this->remove($root);
    }

    public function unavailableApiLeavesEnvironmentUntouched(): void
    {
        $root = $this->root();
        $server = $this->server($root);

        generateSmfResources($server, static fn (): bool => false, $root, 1);

        Assert::same($server->calls, []);
        Assert::same($this->snapshot($root), []);
        $this->remove($root);
    }

    public function doesNotReplaceFieldsForOtherServices(): void
    {
        require_once __DIR__ . '/../../examples/moonshine-admin/dockerfiles/moonshine-v/app/ApiAdmin/Resources/SmfPageFields.php';

        $page = new class {
            public function getResourceOrFail(): object
            {
                return new class {
                    public function getDeclaredEndpoint(): string
                    {
                        return '/members';
                    }

                    public function isSmfForumResource(): bool
                    {
                        return false;
                    }
                };
            }
        };
        $generated = [new stdClass];
        $fields = \App\ApiAdmin\Resources\SmfPageFields::class;

        Assert::same($fields::index($page, $generated), $generated);
        Assert::same($fields::form($page, $generated), $generated);
        Assert::same($fields::detail($page, $generated), $generated);
        Assert::same($fields::endpoint($page->getResourceOrFail()), null);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/moonshine-generator-' . bin2hex(random_bytes(6));
        mkdir($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum', 0777, true);

        return $root;
    }

    private function server(string $root): object
    {
        return new class($root) {
            public array $calls = [];
            public array $payloads = [];
            public array $created = [];

            public function __construct(private string $root) {}

            public function executeTool(string $tool, array $payload): array
            {
                $this->calls[] = $tool;
                $this->payloads[] = [$tool, $payload];
                if ($tool === 'register_service') {
                    return ['success' => true];
                }

                $name = ucfirst($payload['name']);
                $dir = $this->root . "/app/MoonShine/ApiAdmin/Resources/SmfForum/{$name}";
                mkdir($dir . '/Pages', 0777, true);
                $namespace = "App\\MoonShine\\ApiAdmin\\Resources\\SmfForum\\{$name}";
                $resourceFile = $dir . "/{$name}Resource.php";
                $this->render('resource', $resourceFile, [
                    'namespace' => $namespace,
                    'className' => $name . 'Resource',
                    'resourceBaseName' => $name,
                    'title' => $payload['title'],
                    'serviceName' => $payload['service'],
                    'panel' => $payload['panel'],
                    'endpoint' => $payload['endpoint'],
                    'searchFields' => implode(', ', array_map(static fn (string $column): string => var_export($column, true), $payload['search'])),
                ]);

                foreach (['Index' => 'indexFields', 'Detail' => 'detailFields', 'Form' => 'formLayout'] as $suffix => $slot) {
                    $fields = array_filter($payload['fields'], static fn (array $field): bool => ! in_array(strtolower($suffix), $field['hide_on'] ?? [], true));
                    $code = implode("\n", array_map(static fn (array $field): string => sprintf(
                        '            Text::make(%s, %s),',
                        var_export($field['label'], true),
                        var_export($field['column'], true),
                    ), $fields));
                    $this->render(strtolower($suffix) . '-page', $dir . "/Pages/{$name}{$suffix}Page.php", [
                        'namespace' => $namespace . '\\Pages',
                        'className' => $name . $suffix . 'Page',
                        'resourceNamespace' => $namespace,
                        'resourceClass' => $name . 'Resource',
                        'useStatements' => 'use MoonShine\\UI\\Fields\\Text;',
                        $slot => $slot === 'formLayout' ? "            Box::make([\n{$code}\n            ])," : $code,
                    ]);
                }
                $this->created[] = $name;

                return ['success' => true, 'file' => $dir . "/{$name}Resource.php"];
            }
            private function render(string $stub, string $output, array $values): void
            {
                $directory = __DIR__ . '/../../examples/moonshine-admin/dockerfiles/moonshine-v/stubs/api-admin';
                $optionalSlots = match ($stub) {
                    'resource' => ['groupMethod', 'iconUse', 'iconAttribute', 'actionsMethod', 'mutationsProperty', 'defaultQueryProperty', 'indexRequestProperty', 'detailStrategyOverrideMethod', 'responseMappingProperty'],
                    'index-page' => ['filterFields'],
                    'form-page' => ['rulesMethod'],
                    default => [],
                };
                $values += array_fill_keys($optionalSlots, '');
                $replacements = [];

                foreach ($values as $name => $value) {
                    $replacements['{{ ' . $name . ' }}'] = $value;
                }

                file_put_contents($output, strtr(file_get_contents($directory . '/' . $stub . '.stub'), $replacements));
            }
        };
    }

    private function snapshot(string $root): array
    {
        $files = [];
        foreach (glob($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/*/*.php') ?: [] as $file) {
            $files[$file] = file_get_contents($file);
        }
        foreach (glob($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/*/Pages/*.php') ?: [] as $file) {
            $files[$file] = file_get_contents($file);
        }

        ksort($files);

        return $files;
    }

    private function remove(string $root): void
    {
        foreach (array_reverse(array_merge(glob($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/*/Pages/*.php') ?: [], glob($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/*/*.php') ?: [])) as $file) {
            unlink($file);
        }
        foreach (array_reverse(glob($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum/*') ?: []) as $dir) {
            rmdir($dir . '/Pages');
            rmdir($dir);
        }
        rmdir($root . '/app/MoonShine/ApiAdmin/Resources/SmfForum');
        rmdir($root . '/app/MoonShine/ApiAdmin/Resources');
        rmdir($root . '/app/MoonShine/ApiAdmin');
        rmdir($root . '/app/MoonShine');
        rmdir($root . '/app');
        rmdir($root);
    }
}
