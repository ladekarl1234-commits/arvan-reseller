<?php
namespace ArvanReseller\Admin;

defined('ABSPATH') || exit;

/**
 * Contrast guard for the brand colour.
 *
 * `--arvrs-brand` is not decoration: it paints the product CTA, the wallet
 * chip, the active nav link, the primary button gradient and every
 * `:focus-visible` ring, and every one of those places puts **white** text or
 * a white glyph on top. A bare `<input type="color">` invites a light teal,
 * lime or yellow — so the setting could silently drop the whole storefront
 * below AA with no warning anywhere. `Options::BRAND_COLOR` (`#0c6960`,
 * 6.55:1) is the shipped default precisely because it already clears AA on
 * its own; this guard is what stops an admin's own pick from failing it.
 *
 * Rather than reject the reseller's brand, we keep its hue and darken it until
 * white clears 4.5:1, then tell the admin exactly what happened. That is the
 * only remedy that needs nothing from the front-end stylesheet: whatever this
 * returns is safe for every existing white-on-brand rule.
 */
final class Brand
{
    /** WCAG 2.1 AA for normal text. */
    public const MIN_CONTRAST = 4.5;

    /** The one brand colour lives in Options::BRAND_COLOR; this is not a second value. */
    public const FALLBACK = \ArvanReseller\Support\Options::BRAND_COLOR;

    /** Relative luminance (WCAG 2.1 §relative luminance) of a #rrggbb colour. */
    public static function luminance(string $hex): float
    {
        $rgb   = self::rgb($hex);
        $parts = [0.2126, 0.7152, 0.0722];
        $sum   = 0.0;
        foreach ($rgb as $i => $channel) {
            $c = $channel / 255;
            $c = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
            $sum += $parts[$i] * $c;
        }
        return $sum;
    }

    /** Contrast ratio of white text on this background, 1.0–21.0. */
    public static function contrast_with_white(string $hex): float
    {
        return round(1.05 / (self::luminance($hex) + 0.05), 2);
    }

    /**
     * The nearest colour to the submitted one that keeps white text at AA.
     *
     * @return array{color:string,ratio:float,adjusted:bool,submitted:string}
     */
    public static function accessible(string $hex): array
    {
        $hex   = self::normalise($hex);
        $ratio = self::contrast_with_white($hex);
        if ($ratio >= self::MIN_CONTRAST) {
            return ['color' => $hex, 'ratio' => $ratio, 'adjusted' => false, 'submitted' => $hex];
        }

        // Scale all three channels toward black in small steps: the hue and
        // saturation the reseller picked survive, only the lightness moves.
        // Bounded — 60 steps of 0.94 reaches black, and black always passes.
        $rgb = self::rgb($hex);
        for ($i = 0; $i < 60; $i++) {
            foreach ($rgb as $k => $channel) {
                $rgb[$k] = (int) floor($channel * 0.94);
            }
            $candidate = self::hex($rgb);
            $ratio     = self::contrast_with_white($candidate);
            if ($ratio >= self::MIN_CONTRAST) {
                return ['color' => $candidate, 'ratio' => $ratio, 'adjusted' => true, 'submitted' => $hex];
            }
        }
        return ['color' => '#000000', 'ratio' => 21.0, 'adjusted' => true, 'submitted' => $hex];
    }

    /** '#abc' / 'abcdef' / garbage → '#rrggbb'. */
    public static function normalise(string $hex): string
    {
        $hex = strtolower(ltrim(trim($hex), '#'));
        if (preg_match('/^[0-9a-f]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return self::FALLBACK;
        }
        return '#' . $hex;
    }

    /** @return int[] [r,g,b] */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalise($hex), '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @param int[] $rgb */
    private static function hex(array $rgb): string
    {
        $out = '#';
        foreach ($rgb as $channel) {
            $out .= str_pad(dechex(max(0, min(255, $channel))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}
