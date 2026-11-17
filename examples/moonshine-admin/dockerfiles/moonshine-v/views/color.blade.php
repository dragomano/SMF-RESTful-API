@props(['color'])
{{--
    Preview of MoonShine's Color field (tables and detail pages): the swatch
    alone. The stock view also prints the hex value next to it, which widens
    the column; the value stays available as a native tooltip on hover.

    The form itself uses fields/color.blade.php (picker + text input) and is
    not affected.
--}}
<span
    class="inline-block size-4 rounded-sm align-middle"
    style="background-color: {{ $color }}"
    title="{{ $color }}"
></span>
