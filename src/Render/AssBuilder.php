<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Every text of the montage in one ASS file, burnt in with libass: the texts on screen
 * (hook, labels, price, call to action), the words of the text cards and of the product pieces,
 * and the subtitles word by word in the style of TikTok or Reels: few big words at a time, and
 * the one being said in the colour of the brand.
 *
 * Lessons from real tests:
 * - Safe zone of TikTok and Reels: nothing in the top 12 %, the bottom 20 % nor the right 12 %
 *   of the frame, where the apps lay their buttons and texts (SAFE_*).
 * - The word being said always stands out from what is behind it: over a piece on the colour of
 *   the brand (layout «plain») it takes BrandStyle::highlightOn() of that colour.
 * - The offer is white on a dark box, whatever the colours of the brand.
 * - A group of subtitles fits in one line (a limit of characters as well as of words): three
 *   long words took three lines and covered the texts below.
 * - With a fade between two pieces (layout «fades») the texts fade with the picture.
 *
 * Pure: builds the text of the file from the times of the voice.
 */
final class AssBuilder
{
    /** Words shown together in the subtitles, at most. */
    public const GROUP_WORDS = 3;
    /** A silence longer than this starts a new group of words. */
    public const GROUP_GAP = 0.6;
    /** Safe zone: shares of the frame without texts nor logo. */
    public const SAFE_TOP = 0.12;
    public const SAFE_BOTTOM = 0.2;
    public const SAFE_SIDE = 0.12;
    /** Box of the small logo of the montage, top left inside the safe zone (FfmpegRenderer). */
    public const LOGO_LEFT = 0.06;
    public const LOGO_WIDTH = 0.2;
    public const LOGO_HEIGHT = 0.07;
    /** Width of an average character of the subtitles, in shares of their size (bold fonts). */
    private const CHAR_WIDTH = 0.56;

    /**
     * @param list<array{kind: string, text: string, start: float, end: float, position: string}> $overlays
     * @param list<array{text: string, start: float, end: float, style?: string}>                 $cards    style: Card (default), CardBox or Under
     * @param list<array{name: string, offer: string, start: float, end: float}>                  $products
     * @param list<array{text: string, start: float, end: float}>                                 $words    empty when the subtitles are off
     * @param array{plain?: list<array{0: float, 1: float, 2?: string}>, fades?: list<array{at: float, out: float, in: float}>, logo?: bool} $layout
     *                                                                                             plain: windows of pieces on a plain colour, the primary unless a third value says another (a logo_card on the colour that stands out from the logo); fades: fades between pieces; logo: the small logo is on screen
     */
    public static function build(int $width, int $height, BrandStyle $style, array $overlays, array $cards, array $products, array $words, array $layout = []): string
    {
        $h = $height;
        $size = static fn (float $share) => max(8, (int) round($h * $share));
        $line = static fn (float $share) => max(1, (int) round($h * $share));
        $white = '&H00FFFFFF';
        $black = '&H00000000';
        $dark = '&H20000000';
        $shade = '&H80000000';
        $primary = BrandStyle::ass($style->primary);
        $onPrimary = BrandStyle::ass($style->textOnPrimary());
        $font = $style->font;
        $margin = (int) round($width * self::SAFE_SIDE);
        // Below the small logo when it is on screen, so the hook never covers it.
        $top = self::SAFE_TOP + (true === ($layout['logo'] ?? false) ? self::LOGO_HEIGHT + 0.02 : 0.0);
        $subSize = $size(0.058);
        // Name, Fontname, Fontsize, Primary, Secondary, Outline, Back, Bold, Italic, Underline, StrikeOut,
        // ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
        $styles = [
            ['Sub', $subSize, $white, $black, $shade, 1, 1, $line(0.004), 0, 2, $margin, (int) round($h * self::SAFE_BOTTOM)],
            ['Top', $size(0.042), $onPrimary, $primary, $primary, 1, 3, $line(0.008), 0, 8, $margin, (int) round($h * $top)],
            ['Center', $size(0.052), $white, $black, $shade, 1, 1, $line(0.004), 0, 5, $margin, 0],
            ['Bottom', $size(0.042), $onPrimary, $primary, $primary, 1, 3, $line(0.008), 0, 2, $margin, (int) round($h * 0.34)],
            ['Card', $size(0.062), $onPrimary, $primary, $primary, 1, 1, 0, 0, 5, $margin, 0],
            // The text of a card over a generated background, in a box of the brand.
            ['CardBox', $size(0.056), $onPrimary, $primary, $primary, 1, 3, $line(0.012), 0, 5, $margin, 0],
            // The short text under the big logo of a logo_card (the logo ends at 51 % of the
            // height): from 53 %, above the «Bottom» texts (from 60 %) and the subtitles (from 66 %).
            ['Under', $size(0.045), $onPrimary, $primary, $primary, 1, 3, $line(0.01), 0, 8, $margin, (int) round($h * 0.53)],
            // The product piece (FfmpegRenderer): its name from the top of the safe zone, its offer
            // under it, the image of the product from 33 % to 67 % of the height.
            ['Name', $size(0.045), $onPrimary, $primary, $primary, 1, 1, 0, 0, 8, $margin, (int) round($h * self::SAFE_TOP)],
            ['Offer', $size(0.05), $white, $dark, $dark, 1, 3, $line(0.01), 0, 8, $margin, (int) round($h * 0.2)],
        ];
        $out = [
            '[Script Info]',
            'ScriptType: v4.00+',
            'PlayResX: '.$width,
            'PlayResY: '.$height,
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
        ];
        foreach ($styles as [$name, $fontSize, $fill, $outline, $back, $bold, $border, $outlineSize, $shadow, $alignment, $marginLr, $marginV]) {
            $out[] = \sprintf('Style: %s,%s,%d,%s,%s,%s,%s,%d,0,0,0,100,100,0,0,%d,%d,%d,%d,%d,%d,%d,1',
                $name, $font, $fontSize, $fill, $fill, $outline, $back, $bold ? -1 : 0, $border, $outlineSize, $shadow, $alignment, $marginLr, $marginLr, $marginV);
        }
        $out[] = '';
        $out[] = '[Events]';
        $out[] = 'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text';
        $fades = \is_array($layout['fades'] ?? null) ? $layout['fades'] : [];
        $event = static function (int $layer, float $start, float $end, string $styleName, string $text) use (&$out, $fades): void {
            if ($end - $start < 0.01 || '' === $text) {
                return;
            }
            $tags = self::fadeTags($start, $end, $fades);
            $out[] = \sprintf('Dialogue: %d,%s,%s,%s,,0,0,0,,%s', $layer, self::time($start), self::time($end), $styleName, ('' === $tags ? '' : '{'.$tags.'}').$text);
        };

        foreach ($cards as $card) {
            $event(1, $card['start'], $card['end'], \in_array($card['style'] ?? null, ['CardBox', 'Under'], true) ? $card['style'] : 'Card', '{\fad(150,150)}'.self::text($card['text']));
        }
        foreach ($products as $product) {
            $event(1, $product['start'], $product['end'], 'Name', self::text($product['name']));
            $event(1, $product['start'], $product['end'], 'Offer', self::text($product['offer']));
        }
        foreach ($overlays as $overlay) {
            if ('logo' === $overlay['kind']) {
                continue;
            }
            $styleName = match ($overlay['position']) {
                'top' => 'Top',
                'center' => 'Center',
                default => 'Bottom',
            };
            $event(2, $overlay['start'], $overlay['end'], $styleName, '{\fad(200,200)}'.self::text($overlay['text']));
        }
        $plain = \is_array($layout['plain'] ?? null) ? $layout['plain'] : [];
        $usable = $width - 2 * $margin;
        $maxChars = max(8, (int) floor($usable / ($subSize * self::CHAR_WIDTH)));
        foreach (self::groups($words, $maxChars) as $group) {
            $last = \count($group['words']) - 1;
            foreach ($group['words'] as $k => $word) {
                $start = 0 === $k ? $group['start'] : $word['start'];
                $end = $k < $last ? $group['words'][$k + 1]['start'] : $group['end'];
                $under = self::over($start, $plain);
                $highlight = BrandStyle::ass(null !== $under ? $style->highlightOn('' === $under ? $style->primary : $under) : $style->highlight());
                $parts = [];
                foreach ($group['words'] as $j => $other) {
                    $parts[] = $j === $k ? '{\1c'.$highlight.'&}'.self::text($other['text']).'{\1c&H00FFFFFF&}' : self::text($other['text']);
                }
                $event(3, $start, max($start + 0.05, $end), 'Sub', implode(' ', $parts));
            }
        }

        return implode("\n", $out)."\n";
    }

    /**
     * Words in groups of up to three that fit in one line ($maxChars, 0 without limit), cut after
     * a punctuation mark or a silence; each group stays on screen until a little after its last
     * word, never past the next one. A single word longer than the line goes alone.
     *
     * @param list<array{text: string, start: float, end: float}> $words
     *
     * @return list<array{words: list<array{text: string, start: float, end: float}>, start: float, end: float}>
     */
    public static function groups(array $words, int $maxChars = 0): array
    {
        $groups = [];
        $current = [];
        $chars = 0;
        foreach ($words as $i => $word) {
            $previous = $words[$i - 1] ?? null;
            $length = mb_strlen((string) $word['text']);
            if ([] !== $current && (\count($current) >= self::GROUP_WORDS
                || ($maxChars > 0 && $chars + 1 + $length > $maxChars)
                || ($word['start'] - (float) $previous['end']) > self::GROUP_GAP
                || 1 === preg_match('/[.,;:!?…]$/u', (string) $previous['text']))) {
                $groups[] = $current;
                $current = [];
                $chars = 0;
            }
            $chars += ([] === $current ? 0 : 1) + $length;
            $current[] = $word;
        }
        if ([] !== $current) {
            $groups[] = $current;
        }
        $out = [];
        foreach ($groups as $g => $group) {
            $lastEnd = (float) end($group)['end'];
            $next = isset($groups[$g + 1]) ? (float) $groups[$g + 1][0]['start'] : $lastEnd + 0.4;
            $out[] = ['words' => $group, 'start' => (float) $group[0]['start'], 'end' => round(min($next, $lastEnd + 0.4), 3)];
        }

        return $out;
    }

    /**
     * The tags that fade an event out and in with the picture around each fade between pieces
     * that it overlaps: transparent at the cut, opaque again when the next piece is in.
     *
     * @param list<array{at: float, out: float, in: float}> $fades
     */
    public static function fadeTags(float $start, float $end, array $fades): string
    {
        $tags = '';
        $ms = static fn (float $seconds): int => (int) round(max(0.0, $seconds - $start) * 1000);
        foreach ($fades as $fade) {
            $from = $fade['at'] - $fade['out'];
            $to = $fade['at'] + $fade['in'];
            if ($end <= $from || $start >= $to) {
                continue;
            }
            if ($start >= $fade['at']) {
                // It starts while the picture comes back: it comes back with it.
                $tags .= \sprintf('\alpha&HFF&\t(0,%d,\alpha&H00&)', $ms($to));
            } else {
                $tags .= \sprintf('\t(%d,%d,\alpha&HFF&)\t(%d,%d,\alpha&H00&)', $ms($from), $ms($fade['at']), $ms($fade['at']), $ms($to));
            }
        }

        return $tags;
    }

    /** «0:00:01.25» */
    public static function time(float $seconds): string
    {
        $cs = (int) round(max(0, $seconds) * 100);

        return \sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs, 6000) % 60, intdiv($cs, 100) % 60, $cs % 100);
    }

    /**
     * The colour of the plain window at $at (the primary colour when the window does not say
     * one), or null outside them.
     *
     * @param list<array{0: float, 1: float, 2?: string}> $windows
     */
    private static function over(float $at, array $windows): ?string
    {
        foreach ($windows as $window) {
            if ($at >= $window[0] - 0.001 && $at < $window[1]) {
                return $window[2] ?? '';
            }
        }

        return null;
    }

    private static function text(string $text): string
    {
        $text = str_replace(['\\', '{', '}'], ['', '(', ')'], trim($text));

        return str_replace(["\r\n", "\n"], '\N', $text);
    }
}
