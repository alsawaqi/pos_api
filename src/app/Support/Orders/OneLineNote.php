<?php

declare(strict_types=1);

namespace App\Support\Orders;

/**
 * Fix order A-1 (pos_web review H1) — a line note or combo-pick note is ONE
 * line: no note can start a new line on a kitchen ticket.
 *
 *  - CR, LF and tab become a space;
 *  - every other control character (C0, DEL, C1) and the Unicode line /
 *    paragraph separators (U+2028 / U+2029) is dropped;
 *  - runs of spaces collapse to one, and the ends are trimmed;
 *  - at most {@see MAX} code points: the public QR requests refuse a longer
 *    cleaned note (422); device writes cut it (a paid sale is never refused).
 */
final class OneLineNote
{
    public const MAX = 140;

    /** The cleaned note; null stays null, anything that is not a string is returned as is. */
    public static function clean(mixed $note): mixed
    {
        if (! is_string($note)) {
            return $note;
        }
        $text = preg_replace('/[\r\n\t]/u', ' ', $note);
        if ($text === null) {
            // Invalid UTF-8: keep the bytes that form valid characters.
            $text = (string) preg_replace('/[\r\n\t]/', ' ', mb_convert_encoding($note, 'UTF-8', 'UTF-8'));
        }
        $text = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', '', $text);
        $text = (string) preg_replace('/ {2,}/', ' ', $text);

        return trim($text, ' ');
    }

    /** Cleaned and cut to {@see MAX} code points (devices: never refused). */
    public static function cut(mixed $note): mixed
    {
        $text = self::clean($note);
        if (! is_string($text) || mb_strlen($text) <= self::MAX) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX), ' ');
    }

    /**
     * The QR line shape (`lines.*.notes`, `lines.*.combo.*.notes`) with every
     * note cleaned, and cut too when $cut.
     */
    public static function inLines(mixed $lines, bool $cut = false): mixed
    {
        if (! is_array($lines)) {
            return $lines;
        }
        $apply = static fn (mixed $note): mixed => $cut ? self::cut($note) : self::clean($note);
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            if (array_key_exists('notes', $line)) {
                $line['notes'] = $apply($line['notes']);
            }
            if (is_array($line['combo'] ?? null)) {
                foreach ($line['combo'] as $pick => $choice) {
                    if (is_array($choice) && array_key_exists('notes', $choice)) {
                        $line['combo'][$pick]['notes'] = $apply($choice['notes']);
                    }
                }
            }
            $lines[$index] = $line;
        }

        return $lines;
    }
}
