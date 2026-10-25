<?php

declare(strict_types=1);

namespace App\Render;

/**
 * The colours of a logo, read with GD from a small copy of it.
 *
 * - main(): its two main colours, proposed as the colours of the brand when the person uploads
 *   it. White, black, transparent pixels and the colour of its background (the corners) do not
 *   count: a logo on a white square would otherwise always propose white.
 * - average(): its average visible colour, to pick a background it stands out on (the logo of a
 *   logo_card disappeared over a background of its own colour).
 */
final class LogoColors
{
    private const SAMPLE = 64;
    /** Two colours closer than this (RGB distance) are the same colour. */
    private const MIN_DISTANCE = 60.0;

    /**
     * @return list<string> up to two «#RRGGBB», the most used first
     */
    public static function main(string $path): array
    {
        $pixels = self::pixels($path);
        if ([] === $pixels) {
            return [];
        }
        $corners = self::corners($path);
        $buckets = [];
        foreach ($pixels as [$r, $g, $b, $alpha]) {
            if ($alpha < 0.5 || self::neutral($r, $g, $b)) {
                continue;
            }
            foreach ($corners as $corner) {
                if (self::distance([$r, $g, $b], $corner) < 30.0) {
                    continue 2;
                }
            }
            $key = ($r >> 4).'-'.($g >> 4).'-'.($b >> 4);
            $buckets[$key] ??= [0, 0, 0, 0];
            $buckets[$key][0] += $r;
            $buckets[$key][1] += $g;
            $buckets[$key][2] += $b;
            ++$buckets[$key][3];
        }
        uasort($buckets, static fn (array $a, array $b) => $b[3] <=> $a[3]);
        $chosen = [];
        foreach ($buckets as [$r, $g, $b, $count]) {
            $color = [(int) round($r / $count), (int) round($g / $count), (int) round($b / $count)];
            foreach ($chosen as $other) {
                if (self::distance($color, $other) < self::MIN_DISTANCE) {
                    continue 2;
                }
            }
            $chosen[] = $color;
            if (2 === \count($chosen)) {
                break;
            }
        }

        return array_map(self::hex(...), $chosen);
    }

    /** The average colour of its visible pixels, or null when it has none. */
    public static function average(string $path): ?string
    {
        $sum = [0.0, 0.0, 0.0];
        $weight = 0.0;
        foreach (self::pixels($path) as [$r, $g, $b, $alpha]) {
            $sum[0] += $r * $alpha;
            $sum[1] += $g * $alpha;
            $sum[2] += $b * $alpha;
            $weight += $alpha;
        }
        if ($weight < 1.0) {
            return null;
        }

        return self::hex([(int) round($sum[0] / $weight), (int) round($sum[1] / $weight), (int) round($sum[2] / $weight)]);
    }

    /**
     * The background of a logo_card on a plain colour: the colour of the brand that stands out
     * most from the logo; white or almost black when none reaches 3:1.
     */
    public static function backgroundFor(?string $logoAverage, BrandStyle $style): string
    {
        if (null === $logoAverage) {
            return $style->primary;
        }
        $best = null;
        foreach ([$style->primary, $style->secondary] as $color) {
            $contrast = BrandStyle::contrast($color, $logoAverage);
            if ($contrast >= 3.0 && (null === $best || $contrast > $best[1])) {
                $best = [$color, $contrast];
            }
        }
        if (null !== $best) {
            return $best[0];
        }

        return BrandStyle::contrast('#FFFFFF', $logoAverage) >= BrandStyle::contrast('#111111', $logoAverage) ? '#FFFFFF' : '#111111';
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: float}> r, g, b and opacity from 0 to 1
     */
    private static function pixels(string $path): array
    {
        $image = self::small($path);
        if (null === $image) {
            return [];
        }
        $out = [];
        for ($y = 0; $y < imagesy($image); ++$y) {
            for ($x = 0; $x < imagesx($image); ++$x) {
                $c = imagecolorat($image, $x, $y);
                $out[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, 1 - (($c >> 24) & 0x7F) / 127];
            }
        }

        return $out;
    }

    /**
     * The opaque colours of the four corners: the background of the logo, when it has one.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function corners(string $path): array
    {
        $image = self::small($path);
        if (null === $image) {
            return [];
        }
        $w = imagesx($image) - 1;
        $h = imagesy($image) - 1;
        $out = [];
        foreach ([[0, 0], [$w, 0], [0, $h], [$w, $h]] as [$x, $y]) {
            $c = imagecolorat($image, $x, $y);
            if ((($c >> 24) & 0x7F) < 64) {
                $out[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
            }
        }

        return $out;
    }

    private static function small(string $path): ?\GdImage
    {
        $bytes = @file_get_contents($path);
        $source = false === $bytes ? false : @imagecreatefromstring($bytes);
        if (false === $source) {
            return null;
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, self::SAMPLE / max($w, $h));
        $image = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $w, $h);

        return $image;
    }

    private static function neutral(int $r, int $g, int $b): bool
    {
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        return ($min > 230) || ($max < 30) || ($max - $min < 12 && ($min > 215 || $max < 45));
    }

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     */
    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private static function hex(array $rgb): string
    {
        return \sprintf('#%02X%02X%02X', ...$rgb);
    }
}
