<?php

namespace App\Services\RequestText;

/**
 * Text normalisation for matching place names in Bangla / English messages.
 * Mirrors roktodut-ml-service/nlp/normalize.py closely enough for substring matching.
 *
 * No intl/Normalizer dependency (not installed everywhere), so the few Bangla letters
 * that have two Unicode spellings are unified by hand. Characters are built with
 * mb_chr() because several of them look identical, or invisible, in an editor.
 */
final class BanglaText
{
    /** @var array<string, string>|null */
    private static ?array $map = null;

    public static function normalize(string $text): string
    {
        $text = strtr($text, self::map());

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /** True when $needle (already normalised) occurs in $haystack with sensible word boundaries. */
    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        $quoted = preg_quote($needle, '/');
        $isAscii = !preg_match('/[^\x00-\x7F]/', $needle);

        // ASCII names need a boundary on both sides ("united" must not match "reunited").
        // Bangla names only need one on the left: Bangla adds case endings straight onto
        // the word (ঢাকা মেডিকেলে, স্কয়ারে), so the right side stays open.
        $pattern = $isAscii
            ? '/(?<![a-z0-9])' . $quoted . '(?![a-z0-9])/u'
            : '/(?<![\x{0980}-\x{09FF}])' . $quoted . '/u';

        return (bool) preg_match($pattern, $haystack);
    }

    /** @return array<string, string> */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $nukta = mb_chr(0x09BC);
        $map = [
            // য় ড় ঢ় written as base letter + nukta -> the single code point
            mb_chr(0x09AF) . $nukta => mb_chr(0x09DF),
            mb_chr(0x09A1) . $nukta => mb_chr(0x09DC),
            mb_chr(0x09A2) . $nukta => mb_chr(0x09DD),
            // a common alternative spelling of "hospital"
            'হসপিটাল' => 'হাসপাতাল',
        ];

        // Bangla digits -> ASCII
        for ($i = 0; $i <= 9; $i++) {
            $map[mb_chr(0x09E6 + $i)] = (string) $i;
        }

        return self::$map = $map;
    }
}
