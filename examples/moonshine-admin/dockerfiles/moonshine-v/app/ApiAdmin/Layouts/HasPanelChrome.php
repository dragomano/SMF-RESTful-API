<?php

declare(strict_types=1);

namespace App\ApiAdmin\Layouts;

use App\ApiAdmin\Resources\MultiPanelRestResource;
use Illuminate\Support\Str;
use MoonShine\ColorManager\Palettes\CyanPalette;
use MoonShine\ColorManager\Palettes\GrayPalette;
use MoonShine\ColorManager\Palettes\GreenPalette;
use MoonShine\ColorManager\Palettes\HalloweenPalette;
use MoonShine\ColorManager\Palettes\LimePalette;
use MoonShine\ColorManager\Palettes\NeutralPalette;
use MoonShine\ColorManager\Palettes\OrangePalette;
use MoonShine\ColorManager\Palettes\PinkPalette;
use MoonShine\ColorManager\Palettes\PurplePalette;
use MoonShine\ColorManager\Palettes\RetroPalette;
use MoonShine\ColorManager\Palettes\RosePalette;
use MoonShine\ColorManager\Palettes\SkyPalette;
use MoonShine\ColorManager\Palettes\SpringPalette;
use MoonShine\ColorManager\Palettes\TealPalette;
use MoonShine\ColorManager\Palettes\ValentinePalette;
use MoonShine\ColorManager\Palettes\WinterPalette;
use MoonShine\ColorManager\Palettes\YellowPalette;
use MoonShine\Contracts\ColorManager\ColorManagerContract;
use MoonShine\Contracts\ColorManager\PaletteContract;
use MoonShine\Contracts\MenuManager\MenuElementContract;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\MenuManager\MenuDivider;
use MoonShine\MenuManager\MenuGroup;
use MoonShine\MenuManager\MenuItem;
use MoonShine\MultiPanels\MultiPanelResource;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\Layout\Footer;

/**
 * Panel chrome for this stack: colour palette switcher, dark mode as the
 * first-run default, and a footer without the upstream copyright/links.
 *
 * Mixed into HasPanelSwitcher — the trait every panel layout already uses —
 * so nothing has to be re-applied to generated layouts.
 *
 * Palette: MoonShine only exposes the palette as a build-time setting, so the
 * choice lives in the session instead. Every `?_palette=<id>` link (rendered
 * by the dropdown) is picked up by colors(), persisted in the session and
 * applied to the ColorManager of the current request — the same mechanism the
 * built-in locale switcher uses for `_lang`.
 *
 * Dark mode: MoonShine persists the light/dark choice in localStorage and
 * seeds it from the OS preference, so the footer emits a tiny bootstrap
 * script that turns dark mode on when the visitor has not chosen yet.
 */
trait HasPanelChrome
{
    /** Query/session key, mirroring MoonShine's own `_lang`. */
    public const string PALETTE_KEY = '_palette';

    public const string PALETTE_SESSION_KEY = 'api_admin_palette';

    /** Palette used until the visitor picks another one. */
    public const string DEFAULT_PALETTE = 'neutral';

    /** Key of the hub panel ("all services") in config('moonshine.panels'). */
    public const string HUB_PANEL_KEY = 'admin';

    /**
     * Menu order for the service panels, keyed by the resource class stem in
     * lower case (`MemberResource` → `member`). Resources missing from the map
     * keep their registration order and follow the listed ones.
     */
    public const array MENU_ORDER = [
        'group'    => 10,
        'member'   => 20,
        'category' => 30,
        'board'    => 40,
        'topic'    => 50,
    ];

    /** A divider is drawn before each resource with these positions. */
    public const array MENU_DIVIDER_BEFORE = [30];

    /**
     * MoonShine icon name per resource, keyed like MENU_ORDER. Every service
     * item is rendered with onlyIcon(), so the collapsed sidebar shows the
     * icon alone (with a tooltip) instead of a truncated label.
     */
    public const array MENU_ICONS = [
        'group'    => 'user-group',
        'member'   => 'users',
        'category' => 'folder',
        'board'    => 'view-columns',
        'topic'    => 'chat-bubble-left',
    ];

    /** localStorage key MoonShine's `darkMode` Alpine store persists to. */
    public const string DARK_MODE_STORAGE_KEY = 'darkMode';

    /**
     * Palette id => class. The ids are the stable part: they appear in the
     * URL, so renaming a palette class must not change them.
     *
     * @return array<string, class-string<PaletteContract>>
     */
    public static function palettes(): array
    {
        return [
            'purple'    => PurplePalette::class,
            'cyan'      => CyanPalette::class,
            'gray'      => GrayPalette::class,
            'green'     => GreenPalette::class,
            'halloween' => HalloweenPalette::class,
            'lime'      => LimePalette::class,
            'neutral'   => NeutralPalette::class,
            'orange'    => OrangePalette::class,
            'pink'      => PinkPalette::class,
            'retro'     => RetroPalette::class,
            'rose'      => RosePalette::class,
            'sky'       => SkyPalette::class,
            'spring'    => SpringPalette::class,
            'teal'      => TealPalette::class,
            'valentine' => ValentinePalette::class,
            'winter'    => WinterPalette::class,
            'yellow'    => YellowPalette::class,
        ];
    }

    /**
     * Store a palette requested through `?_palette=...` and return the
     * palette to render with.
     */
    protected function resolvePaletteId(): string
    {
        $palettes = self::palettes();
        $request = request();

        $requested = $request->input(self::PALETTE_KEY);

        if (is_string($requested) && array_key_exists($requested, $palettes)) {
            if ($request->hasSession()) {
                $request->session()->put(self::PALETTE_SESSION_KEY, $requested);
            }

            return $requested;
        }

        $stored = $request->hasSession()
            ? $request->session()->get(self::PALETTE_SESSION_KEY)
            : null;

        return is_string($stored) && array_key_exists($stored, $palettes)
            ? $stored
            : self::DEFAULT_PALETTE;
    }

    /**
     * Overrides AbstractLayout::colors() so the session palette wins over the
     * configured one (the panel keeps its own palette as the fallback).
     */
    protected function colors(ColorManagerContract $colorManager): void
    {
        $class = self::palettes()[$this->resolvePaletteId()];

        $colorManager->palette(new $class());
    }

    /**
     * Dropdown with a swatch per palette, shown next to the panel switcher.
     */
    protected function paletteSwitcher(): ?ComponentContract
    {
        $current = $this->resolvePaletteId();
        $request = request();

        $items = [];

        foreach (self::palettes() as $id => $class) {
            /** @var PaletteContract $palette */
            $palette = new $class();
            $primary = $palette->getColors()['primary'] ?? '';

            $items[] = [
                'id'      => $id,
                'label'   => __('panel.palette.' . $id),
                'swatch'  => is_string($primary)
                    ? 'oklch(' . str_replace(['rgb(', ')', 'oklch('], '', $primary) . ')'
                    : 'transparent',
                'href'    => $request->fullUrlWithQuery([self::PALETTE_KEY => $id]),
                'active'  => $id === $current,
            ];
        }

        return FlexibleRender::make(
            view('api-admin::components.palette-switcher', [
                'title'   => __('panel.palette.title'),
                'current' => $current,
                'items'   => $items,
            ])
        );
    }

    /**
     * The switcher and the hub link sit in the sidebar header, next to the
     * light/dark toggle.
     *
     * @return list<ComponentContract>
     */
    protected function sidebarTopSlot(): array
    {
        return array_values(array_filter(
            [$this->paletteSwitcher(), $this->hubLink()],
            static fn (mixed $component): bool => $component instanceof ComponentContract,
        ));
    }

    /**
     * Service panel menu with an explicit order.
     *
     * HasPanelSwitcher::panelMenu() renders resources in registration order and
     * ignores the #[Order] attributes that MenuAutoloader honours, so the order
     * is applied here: resources listed in MENU_ORDER come first (members,
     * divider, categories, boards, topics, groups), everything else follows in
     * registration order.
     *
     * @return list<MenuElementContract>
     */
    protected function apiAdminMenu(): array
    {
        $panelName = request()->getPanelName();
        $resources = $this->getCore()->getResources()->toArray();

        $items = [];
        $groups = [];
        $next = 100;

        foreach ($resources as $resource) {
            $resourcePanel = match (true) {
                $resource instanceof MultiPanelRestResource => $resource->getPanel(),
                $resource instanceof MultiPanelResource => $resource->getRouter()->getParam('panelName'),
                default => null,
            };

            if ($resourcePanel !== $panelName) {
                continue;
            }

            $group = $resource->getGroup();
            $key   = Str::of(class_basename($resource))->before('Resource')->lower()->toString();

            $entry = [
                'position' => self::MENU_ORDER[$key] ?? $next++,
                // null keeps the resource's own (localized) title; onlyIcon()
                // hides the label in the collapsed sidebar and keeps a tooltip.
                'element'  => MenuItem::make($resource::class, null, self::MENU_ICONS[$key] ?? null)->onlyIcon(),
            ];

            if ($group !== null) {
                $groups[$group][] = $entry;

                continue;
            }

            $items[] = $entry;
        }

        $byPosition = static fn (array $a, array $b): int => $a['position'] <=> $b['position'];

        usort($items, $byPosition);

        $menu = [];

        foreach ($items as $item) {
            if (\in_array($item['position'], self::MENU_DIVIDER_BEFORE, true)) {
                $menu[] = MenuDivider::make();
            }

            $menu[] = $item['element'];
        }

        foreach ($groups as $label => $groupItems) {
            usort($groupItems, $byPosition);

            $menu[] = MenuGroup::make(
                $label,
                array_map(static fn (array $item): MenuElementContract => $item['element'], $groupItems),
            );
        }

        return $menu;
    }

    /**
     * Way back to the hub ("Services") from inside a service panel.
     *
     * MoonShine's panel switcher lists only service panels once one exists —
     * it filters out the hub — so without this link a service panel has no
     * navigation back to the list of services (only the URL).
     */
    protected function hubLink(): ?ComponentContract
    {
        $panels = config('moonshine.panels', []);
        $hub = is_array($panels) ? ($panels[self::HUB_PANEL_KEY] ?? null) : null;

        $currentPanel = request()->getPanelName();

        if (! is_array($hub) || $currentPanel === self::HUB_PANEL_KEY) {
            return null;
        }

        $prefix = $hub['prefix'] ?? self::HUB_PANEL_KEY;

        return FlexibleRender::make(
            view('api-admin::components.hub-link', [
                'href'  => '/'.ltrim(is_string($prefix) ? $prefix : self::HUB_PANEL_KEY, '/'),
                'label' => __('panel.services'),
            ])
        );
    }

    /**
     * Replaces the upstream footer: no copyright line, no Documentation /
     * MoonShine links. The element stays in the markup (hidden) so the
     * first-run dark mode default is applied before Alpine boots.
     */
    protected function getFooterComponent(): Footer
    {
        return Footer::make([
            FlexibleRender::make($this->darkModeBootstrapScript()),
        ])->customAttributes(['class' => 'hidden']);
    }

    /**
     * MoonShine's `darkMode` Alpine store is persisted in localStorage and
     * defaults to the OS preference. Seeding it once makes dark mode the
     * default for a fresh install while leaving later toggles intact.
     */
    protected function darkModeBootstrapScript(): string
    {
        $key = self::DARK_MODE_STORAGE_KEY;

        return <<<HTML
        <script>
        (function () {
            try {
                if (window.localStorage.getItem('{$key}') === null) {
                    window.localStorage.setItem('{$key}', 'true');
                }
            } catch (e) {
                // Storage can be blocked; the panel then follows the OS theme.
            }
        })();
        </script>
        HTML;
    }
}
