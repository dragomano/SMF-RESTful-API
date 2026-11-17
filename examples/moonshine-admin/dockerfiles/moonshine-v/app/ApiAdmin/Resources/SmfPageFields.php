<?php

declare(strict_types=1);

namespace App\ApiAdmin\Resources;

use Illuminate\Support\Carbon;
use MoonShine\Contracts\Core\DependencyInjection\FieldsContract;
use MoonShine\UI\Fields\Color;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Number;
use MoonShine\UI\Fields\Password;
use MoonShine\UI\Fields\Select;
use MoonShine\UI\Fields\Switcher;
use MoonShine\UI\Fields\Text;
use MoonShine\UI\Fields\Textarea;

/**
 * Canonical SMF resource fields consumed by the generator's page stubs.
 *
 * Keeping fields as PHP definitions means labels, relation widgets and
 * previews are independent of the generated source formatting.
 */
final class SmfPageFields
{
    public static function endpoint(object $resource): ?string
    {
        return method_exists($resource, 'isSmfForumResource') && $resource->isSmfForumResource()
            ? $resource->getDeclaredEndpoint()
            : null;
    }

    /** @return list<object> */
    public static function index(object $page, array $generated): array
    {
        $resource = $page->getResourceOrFail();

        return match (self::endpoint($resource)) {
            '/members' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                Text::make(__('smf_forum.fields.member.name'), 'name')->changePreview(
                    fn ($value, $field) => $resource->selfLink((int) ($field->getData()?->getOriginal()['id'] ?? 0), (string) $value),
                ),
                Text::make(__('smf_forum.fields.member.posts'), 'posts'),
                self::timestamp(__('smf_forum.fields.member.date_registered'), 'date_registered'),
                self::timestamp(__('smf_forum.fields.member.last_login'), 'last_login'),
                Text::make(__('smf_forum.fields.member.primary_group'), 'primary_group')->changePreview(
                    fn ($value) => $resource->groupLink((int) $value),
                ),
                Text::make(__('smf_forum.fields.member.additional_groups'), 'additional_groups')->changePreview(
                    fn ($value) => collect((array) $value)->filter()->map(fn ($id) => $resource->groupLink((int) $id))->implode(' '),
                ),
            ],
            '/categories' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                self::selfLink($resource, __('smf_forum.fields.category.name'), 'name'),
                Text::make(__('smf_forum.fields.category.description'), 'description'),
                Number::make(__('smf_forum.fields.category.order'), 'order'),
            ],
            '/boards' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                Number::make(__('smf_forum.fields.board.category'), 'category')->changePreview(fn ($value) => $resource->categoryLink((int) $value)),
                Number::make(__('smf_forum.fields.board.parent'), 'parent')->changePreview(fn ($value) => $resource->boardLink((int) $value)),
                self::selfLink($resource, __('smf_forum.fields.board.name'), 'name'),
                Text::make(__('smf_forum.fields.board.description'), 'description'),
                Number::make(__('smf_forum.fields.board.num_topics'), 'num_topics'),
                Number::make(__('smf_forum.fields.board.num_posts'), 'num_posts'),
                Number::make(__('smf_forum.fields.board.order'), 'order'),
            ],
            '/topics' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                Number::make(__('smf_forum.fields.topic.board'), 'board')->changePreview(fn ($value) => $resource->boardLink((int) $value)),
                self::selfLink($resource, __('smf_forum.fields.topic.subject'), 'subject'),
                self::timestamp(__('smf_forum.fields.topic.poster_time'), 'poster_time'),
                Number::make(__('smf_forum.fields.topic.num_replies'), 'num_replies'),
                Number::make(__('smf_forum.fields.topic.num_views'), 'num_views'),
                Switcher::make(__('smf_forum.fields.topic.locked'), 'locked'),
                Switcher::make(__('smf_forum.fields.topic.is_sticky'), 'is_sticky'),
                Switcher::make(__('smf_forum.fields.topic.approved'), 'approved'),
            ],
            '/groups' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                self::selfLink($resource, __('smf_forum.fields.group.name'), 'name'),
                Text::make(__('smf_forum.fields.group.description'), 'description'),
                Number::make(__('smf_forum.fields.group.type'), 'type')->changePreview(fn ($value) => __('smf_forum.group_type.' . (int) $value)),
                Number::make(__('smf_forum.fields.group.min_posts'), 'min_posts'),
                Number::make(__('smf_forum.fields.group.max_messages'), 'max_messages'),
                Color::make(__('smf_forum.fields.group.color'), 'color'),
                Switcher::make(__('smf_forum.fields.group.hidden'), 'hidden'),
                Number::make(__('smf_forum.fields.group.parent'), 'parent'),
                Switcher::make(__('smf_forum.fields.group.tfa_required'), 'tfa_required'),
            ],
            default => $generated,
        };
    }

    /** @return list<object> */
    public static function form(object $page, array $generated): array
    {
        $resource = $page->getResourceOrFail();

        return match (self::endpoint($resource)) {
            '/members' => [
                Text::make(__('smf_forum.fields.member.username'), 'username')->required(),
                Text::make(__('smf_forum.fields.member.name'), 'name'),
                \MoonShine\UI\Fields\Email::make(__('smf_forum.fields.member.email'), 'email')->required(),
                Password::make(__('smf_forum.fields.member.password'), 'password')->raw()->required($resource->getItemID() === null),
                Select::make(__('smf_forum.fields.member.primary_group'), 'primary_group')->options(fn () => $resource->groupOptions())->native(),
                Select::make(__('smf_forum.fields.member.additional_groups'), 'additional_groups')->multiple()->options(fn () => $resource->groupOptions()),
            ],
            '/categories' => [
                Text::make(__('smf_forum.fields.category.name'), 'name')->required(),
                Textarea::make(__('smf_forum.fields.category.description'), 'description'),
            ],
            '/boards' => [
                Select::make(__('smf_forum.fields.board.category'), 'category')
                    ->options(fn () => $resource->categoryOptions())
                    ->native()
                    ->required()
                    ->reactive(static fn (FieldsContract $fields, mixed $value) => self::scopeBoardParent($fields, $resource, $value)),
                Select::make(__('smf_forum.fields.board.parent'), 'parent')
                    ->options(fn () => self::boardParentOptions($resource, self::itemColumn($resource, 'category')))
                    ->native()
                    // The reactive category select swaps this field's HTML in
                    // place; the client finds it by these markers and reads
                    // the option labels from [data-reactive-column] — the same
                    // markers Reactivity::reactive() adds to reactive fields.
                    ->wrapperClass('field-parent-wrapper')
                    ->customAttributes([
                        'class' => 'field-parent-element',
                        'data-column' => 'parent',
                        'data-reactive-column' => 'parent',
                    ]),
                Text::make(__('smf_forum.fields.board.name'), 'name')->required(),
                Textarea::make(__('smf_forum.fields.board.description'), 'description'),
            ],
            '/topics' => [
                Select::make(__('smf_forum.fields.topic.board'), 'board')->options(fn () => $resource->boardOptions())->native()->required(),
                Text::make(__('smf_forum.fields.topic.subject'), 'subject')->required(),
                Textarea::make(__('smf_forum.fields.topic.body'), 'body_raw'),
                Switcher::make(__('smf_forum.fields.topic.locked'), 'locked'),
                Switcher::make(__('smf_forum.fields.topic.is_sticky'), 'is_sticky'),
            ],
            '/groups' => [
                Text::make(__('smf_forum.fields.group.name'), 'name')->required(),
                Textarea::make(__('smf_forum.fields.group.description'), 'description'),
                Select::make(__('smf_forum.fields.group.type'), 'type')->options([0 => __('smf_forum.group_type.0'), 1 => __('smf_forum.group_type.1'), 2 => __('smf_forum.group_type.2'), 3 => __('smf_forum.group_type.3')]),
                Number::make(__('smf_forum.fields.group.min_posts'), 'min_posts'),
                Number::make(__('smf_forum.fields.group.max_messages'), 'max_messages'),
                Color::make(__('smf_forum.fields.group.color'), 'color'),
            ],
            default => $generated,
        };
    }

    /** @return list<object> */
    public static function detail(object $page, array $generated): array
    {
        $resource = $page->getResourceOrFail();

        return match (self::endpoint($resource)) {
            '/members' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                Text::make(__('smf_forum.fields.member.name'), 'name')->changePreview(fn ($value) => $resource->selfLink((int) ($resource->getItemID() ?? 0), (string) $value)),
                Text::make(__('smf_forum.fields.member.posts'), 'posts'),
                self::timestamp(__('smf_forum.fields.member.date_registered'), 'date_registered'),
                self::timestamp(__('smf_forum.fields.member.last_login'), 'last_login'),
                Text::make(__('smf_forum.fields.member.primary_group'), 'primary_group')->changePreview(fn ($value) => $resource->groupLink((int) $value)),
                Text::make(__('smf_forum.fields.member.additional_groups'), 'additional_groups')->changePreview(fn ($value) => collect((array) $value)->filter()->map(fn ($id) => $resource->groupLink((int) $id))->implode(' ')),
            ],
            '/categories' => [ID::make(__('smf_forum.fields.id'), 'id'), Text::make(__('smf_forum.fields.category.name'), 'name'), Text::make(__('smf_forum.fields.category.description'), 'description'), Number::make(__('smf_forum.fields.category.order'), 'order')],
            '/boards' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                Number::make(__('smf_forum.fields.board.category'), 'category')->changePreview(fn ($value) => $resource->categoryLink((int) $value)),
                Number::make(__('smf_forum.fields.board.parent'), 'parent')->changePreview(fn ($value) => $resource->boardLink((int) $value)),
                Text::make(__('smf_forum.fields.board.name'), 'name'), Text::make(__('smf_forum.fields.board.description'), 'description'),
                Number::make(__('smf_forum.fields.board.num_topics'), 'num_topics'), Number::make(__('smf_forum.fields.board.num_posts'), 'num_posts'), Number::make(__('smf_forum.fields.board.order'), 'order'),
            ],
            '/topics' => [
                ID::make(__('smf_forum.fields.id'), 'id'),
                Number::make(__('smf_forum.fields.topic.board'), 'board')->changePreview(fn ($value) => $resource->boardLink((int) $value)),
                Text::make(__('smf_forum.fields.topic.subject'), 'subject'),
                Text::make(__('smf_forum.fields.topic.body'), 'body')->changePreview(fn ($value) => (string) $value),
                self::timestamp(__('smf_forum.fields.topic.poster_time'), 'poster_time'),
                Number::make(__('smf_forum.fields.topic.num_replies'), 'num_replies'), Number::make(__('smf_forum.fields.topic.num_views'), 'num_views'),
                Switcher::make(__('smf_forum.fields.topic.locked'), 'locked'), Switcher::make(__('smf_forum.fields.topic.is_sticky'), 'is_sticky'), Switcher::make(__('smf_forum.fields.topic.approved'), 'approved'),
                Number::make(__('smf_forum.fields.topic.id_first_msg'), 'id_first_msg'), Number::make(__('smf_forum.fields.topic.id_last_msg'), 'id_last_msg'),
            ],
            '/groups' => [
                ID::make(__('smf_forum.fields.id'), 'id'), Text::make(__('smf_forum.fields.group.name'), 'name'), Text::make(__('smf_forum.fields.group.description'), 'description'),
                Number::make(__('smf_forum.fields.group.type'), 'type')->changePreview(fn ($value) => __('smf_forum.group_type.' . (int) $value)),
                Number::make(__('smf_forum.fields.group.min_posts'), 'min_posts'), Number::make(__('smf_forum.fields.group.max_messages'), 'max_messages'),
                Color::make(__('smf_forum.fields.group.color'), 'color'), Text::make(__('smf_forum.fields.group.icons'), 'icons'),
                Switcher::make(__('smf_forum.fields.group.hidden'), 'hidden'), Number::make(__('smf_forum.fields.group.parent'), 'parent'), Switcher::make(__('smf_forum.fields.group.tfa_required'), 'tfa_required'),
            ],
            default => $generated,
        };
    }

    /**
     * Parent-board select options: SMF requires a parent board to live in the
     * same category as the board itself, so the list is scoped to the picked
     * category. On a fresh create form the native select displays the first
     * category anyway, so scope to it rather than offering an empty list.
     *
     * @return array<int, string>
     */
    private static function boardParentOptions(object $resource, mixed $category): array
    {
        $categoryId = (int) $category;

        if ($categoryId <= 0) {
            $categoryId = (int) (array_key_first($resource->categoryOptions()) ?? 0);
        }

        return [0 => __('smf_forum.no_parent')]
            + $resource->boardOptionsByCategory($categoryId, self::exceptSelfId($resource));
    }

    /**
     * Current item column value (edit form) or null (create form).
     */
    private static function itemColumn(object $resource, string $column): mixed
    {
        $wrapper = $resource->getCastedData();

        return $wrapper === null ? null : ($wrapper->getOriginal()[$column] ?? null);
    }

    private static function exceptSelfId(object $resource): ?int
    {
        return $resource->getItemID() !== null ? (int) $resource->getItemID() : null;
    }

    /**
     * Reactive rebuild of the parent-board select: a freshly picked category
     * offers that category's boards only, and a stale parent from the previous
     * category resets to "no parent" so the API's same-category rule is never
     * tripped.
     *
     * ReactiveController passes the callback only the form's reactive fields
     * (here: the category select), so the parent field is rebuilt from scratch
     * and appended to the re-rendered set.
     */
    private static function scopeBoardParent(FieldsContract $fields, object $resource, mixed $category): FieldsContract
    {
        return $fields->push(
            Select::make(__('smf_forum.fields.board.parent'), 'parent')
                ->options(self::boardParentOptions($resource, $category))
                ->native()
                ->wrapperClass('field-parent-wrapper')
                ->customAttributes([
                    'class' => 'field-parent-element',
                    'data-column' => 'parent',
                    'data-reactive-column' => 'parent',
                ])
                ->setValue(0)
        );
    }

    private static function timestamp(string $label, string $column): Text
    {
        return Text::make($label, $column, static fn ($row, $index, $field) => ($value = is_array($row) ? ($row[$field->getColumn()] ?? null) : $row)
            ? Carbon::createFromTimestamp((int) $value)->format('d.m.Y H:i')
            : '—');
    }

    private static function selfLink(object $resource, string $label, string $column): Text
    {
        return Text::make($label, $column)->changePreview(
            fn ($value, $field) => $resource->selfLink((int) ($field->getData()?->getOriginal()['id'] ?? 0), (string) $value),
        );
    }
}
