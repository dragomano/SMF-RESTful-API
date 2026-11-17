<?php

declare(strict_types=1);

namespace App\ApiAdmin\Support;

/**
 * Human-readable panel title for a service name.
 *
 * The generators used `Str::title($name).' Admin'`, which turned the service
 * `smf_forum` into "Smf Forum Admin". Short all-caps abbreviations are common
 * in service names (smf, api, crm…), so they are upper-cased instead.
 */
final class PanelTitle
{
    /** Words at or below this length are treated as abbreviations. */
    private const int ABBREVIATION_LENGTH = 3;

    public static function fromServiceName(string $name): string
    {
        $words = preg_split('/[\s_-]+/', trim($name)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));

        $words = array_map(
            static fn (string $word): string => mb_strlen($word) <= self::ABBREVIATION_LENGTH
                ? mb_strtoupper($word)
                : mb_convert_case($word, MB_CASE_TITLE),
            $words,
        );

        return implode(' ', $words).' Admin';
    }
}
