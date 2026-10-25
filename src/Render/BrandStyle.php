<?php

declare(strict_types=1);

namespace App\Render;

/**
 * The colours and the font of the brand for the texts on screen, the cards and the subtitles:
 * the first two colours and the first font the person wrote for the video, as text
 * («#6D4AFF, #FFFFFF», «Montserrat»). Without colours the accent of the app is used. The font
 * is one of the program (VideoFonts); a font of
 * the brand that is not there goes in the default one and is kept in $missingFont, to say so.
 */
final class BrandStyle
{
    public const DEFAULT_PRIMARY = '#6D4AFF';
    public const DEFAULT_FONT = VideoFonts::DEFAULT;
    /** Contrast (WCAG) a coloured word needs against what is behind it. */
    private const MIN_CONTRAST = 3.0;
    private const YELLOW = '#FFD400';

    public function __construct(
        public readonly string $primary,
        public readonly string $secondary,
        public readonly string $font,
        public readonly string $name = '',
        public readonly ?string $missingFont = null,
    ) {
    }

    /**
     * @param array<string, mixed> $brand {name, colors, fonts}
     */
    public static function from(array $brand): self
    {
        preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', (string) ($brand['colors'] ?? ''), $matches);
        $colors = array_map(static function (string $hex): string {
            $hex = strtoupper($hex);

            return '#'.(3 === \strlen($hex) ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex);
        }, $matches[1]);
        $font = trim((string) (preg_split('/[,\n;]+/', (string) ($brand['fonts'] ?? ''))[0] ?? ''));
        $font = mb_substr(trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $font) ?? ''), 0, 60);
        $family = '' === $font ? null : VideoFonts::resolve($font);

        return new self($colors[0] ?? self::DEFAULT_PRIMARY, $colors[1] ?? '#FFFFFF', $family ?? self::DEFAULT_FONT, (string) ($brand['name'] ?? ''), '' !== $font && null === $family ? $font : null);
    }

    /** Text on the primary colour: white on dark colours, almost black on light ones. */
    public function textOnPrimary(): string
    {
        return self::luminance($this->primary) > 0.55 ? '#111111' : '#FFFFFF';
    }

    /** The colour of the word being said: the brand's, unless it is too dark to read on video. */
    public function highlight(): string
    {
        if (self::luminance($this->primary) >= 0.15) {
            return $this->primary;
        }

        return self::luminance($this->secondary) >= 0.15 && '#FFFFFF' !== $this->secondary ? $this->secondary : '#FFD400';
    }

    /**
     * The colour of the word being said over a background of a known colour (a card or
     * a product piece on the colour of the brand): the colour of the brand when it stands out
     * from that background, else the second colour, else yellow or almost black. It is never
     * white, the colour of the other words. Over the brand's own colour the highlight() was
     * the background itself and the word disappeared.
     */
    public function highlightOn(string $background): string
    {
        foreach ([$this->highlight(), $this->primary, $this->secondary, self::YELLOW, '#111111'] as $color) {
            if (self::contrast($color, $background) >= self::MIN_CONTRAST && self::contrast($color, '#FFFFFF') >= 1.5) {
                return $color;
            }
        }

        return self::luminance($background) > 0.5 ? '#111111' : self::YELLOW;
    }

    /** Contrast ratio (WCAG 2) of two colours, from 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $linear = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = static function (float $c): float {
                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel(hexdec(substr($hex, 0, 2)) / 255) + 0.7152 * $channel(hexdec(substr($hex, 2, 2)) / 255) + 0.0722 * $channel(hexdec(substr($hex, 4, 2)) / 255);
        };
        [$x, $y] = [$linear($a), $linear($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** «0xRRGGBB» for the lavfi colour source. */
    public static function ffmpeg(string $hex): string
    {
        return '0x'.ltrim($hex, '#');
    }

    /** «&H00BBGGRR» for ASS. */
    public static function ass(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return '&H00'.strtoupper(substr($hex, 4, 2).substr($hex, 2, 2).substr($hex, 0, 2));
    }

    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{primary: string, secondary: string, font: string, highlight: string}
     */
    public function toArray(): array
    {
        return ['primary' => $this->primary, 'secondary' => $this->secondary, 'font' => $this->font, 'highlight' => $this->highlight()];
    }
}
