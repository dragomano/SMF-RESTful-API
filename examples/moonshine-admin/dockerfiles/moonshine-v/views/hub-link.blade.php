@once
    <style>
        .api-admin-hub-link {
            display: inline-flex;
        }

        .api-admin-hub-link a {
            padding-inline: .5rem;
        }
    </style>
@endonce

<div class="api-admin-hub-link">
    <a href="{{ $href }}" class="dropdown-btn" title="{{ $label }}" aria-label="{{ $label }}">
        <x-moonshine::icon icon="squares-2x2" />
    </a>
</div>
