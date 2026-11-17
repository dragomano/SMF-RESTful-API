@once
    <style>
        .api-admin-palette-switcher .dropdown-btn {
            padding-inline: .5rem;
        }

        .api-admin-palette-switcher__swatch {
            width: 1rem;
            height: 1rem;
            border-radius: 9999px;
            background: var(--swatch);
            box-shadow: inset 0 0 0 1px rgb(0 0 0 / .15);
            flex: none;
        }

        .api-admin-palette-switcher .dropdown-body,
        .api-admin-palette-switcher .dropdown-content {
            width: min(224px, calc(100vw - 1rem));
            min-width: min(224px, calc(100vw - 1rem));
            max-width: min(224px, calc(100vw - 1rem));
            max-height: 24rem;
            overflow-y: auto;
        }
    </style>
@endonce

<div class="api-admin-palette-switcher">
    <x-moonshine::dropdown strategy="fixed">
        <x-slot:toggler class="dropdown-btn" title="{{ $title }}">
            <x-moonshine::icon icon="paint-brush" />
        </x-slot:toggler>

        <ul class="dropdown-menu">
            @foreach($items as $item)
                <li class="dropdown-menu-item">
                    <a
                        href="{{ $item['href'] }}"
                        class="dropdown-menu-link flex w-full items-center gap-2"
                    >
                        <span
                            class="api-admin-palette-switcher__swatch"
                            style="--swatch: {{ $item['swatch'] }}"
                        ></span>
                        <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                        @if($item['active'])
                            <x-moonshine::icon icon="check" class="text-primary" />
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </x-moonshine::dropdown>
</div>
