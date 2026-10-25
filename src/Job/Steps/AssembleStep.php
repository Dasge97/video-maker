<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\Step;
use App\Render\AssBuilder;
use App\Render\BrandStyle;
use App\Render\Ffmpeg;
use App\Render\LogoColors;
use App\Render\Renderer;
use App\Render\VideoFonts;
use App\Sound\SoundCatalog;

/**
 * The montage of a plan: every piece takes the duration its voice fixed (VoiceTimeline), then
 * the Renderer composes pieces, texts, subtitles, logo, voice and music.
 *
 * The images made before the pieces (the one that moves in an image_motion and the backgrounds
 * of logo_card and text_card) come in $media. One that could not be made leaves its piece on
 * the colour of the brand, and the warnings say so.
 */
final class AssembleStep implements Step
{
    public const WIDTH = 720;
    public const HEIGHT = 1280;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly VideoFonts $fonts,
        private readonly SoundCatalog $sound,
        private readonly Ffmpeg $ffmpeg,
    ) {
    }

    public function name(): string
    {
        return 'assemble';
    }

    /** Always made again: it is free, and texts, music or subtitles may have changed. */
    public function run(Job $job): bool
    {
        $outputs = $job->data['outputs'];
        $timeline = json_decode((string) file_get_contents($job->path((string) $outputs['timeline'])), true, 512, \JSON_THROW_ON_ERROR);
        $path = static fn (?string $relative): ?string => null === $relative ? null : $job->path($relative);
        $images = [];
        foreach ($outputs['images'] ?? [] as $i => $files) {
            $images[(int) $i] = array_map($path, $files);
        }
        $brand = $job->input()['brand'];
        $style = BrandStyle::from(['name' => $brand['name'] ?? '', 'colors' => $brand['colors'] ?? '', 'fonts' => $brand['font'] ?? '']);
        $out = $job->path('out/video.mp4');
        $temp = $job->path('out/video-new.mp4');
        $result = $this->assemble((array) $job->plan(), $timeline, [
            'clips' => array_map($path, $outputs['clips'] ?? []),
            'images' => $images,
            'voice' => $path($outputs['voice'] ?? null),
            'music' => $path($outputs['music'] ?? null),
            'logo' => true === ($job->input()['hasLogo'] ?? false) ? $job->path('input/logo.png') : null,
        ], $style, $temp);
        // The new video replaces the old one only when it is whole.
        @unlink($out);
        rename($temp, $out);
        $this->ffmpeg->exec(['ffmpeg', '-v', 'error', '-ss', '1', '-i', $out, '-frames:v', '1', '-q:v', '3', '-y', $job->path('out/poster.jpg')]);
        foreach ($result['warnings'] as $warning) {
            $job->warn($warning);
        }
        $job->data['result'] = ['file' => 'out/video.mp4', 'durationSeconds' => $result['durationSeconds']];
        $job->log(\sprintf('Montaje hecho: %s s', $result['durationSeconds']));

        return true;
    }

    /**
     * @param array<string, mixed> $plan     the plan of the agent (plan.schema.json)
     * @param array<string, mixed> $timeline VoiceTimeline::build, with keys «p<index>»; empty: planned seconds and no subtitles
     * @param array{clips?: array<int, string>, images?: array<int, array{start?: string, background?: string}>, voice?: string|null, music?: string|null, logo?: string|null} $media by piece index
     *
     * @return array{durationSeconds: float, warnings: list<string>}
     */
    public function assemble(array $plan, array $timeline, array $media, BrandStyle $style, string $out): array
    {
        $width = self::WIDTH;
        $height = self::HEIGHT;
        $warnings = [];
        if (null !== $style->missingFont) {
            $warnings[] = \sprintf('La fuente «%s» de la marca no está en el programa: los textos van en %s.', $style->missingFont, $style->font);
        }
        $timed = [];
        foreach ($timeline['pieces'] ?? [] as $piece) {
            $timed[(string) $piece['key']] = $piece;
        }
        $logoFile = \is_string($media['logo'] ?? null) && is_file($media['logo']) ? $media['logo'] : null;
        $image = static function (int $i, string $kind) use ($media): ?string {
            $path = $media['images'][$i][$kind] ?? null;

            return \is_string($path) && is_file($path) ? $path : null;
        };

        $pieces = [];
        $windows = [];
        $cards = [];
        // The pieces on a plain colour (the word of the subtitles changes colour there and the
        // small logo is not laid over them) and the fades.
        $plain = [];
        $fades = [];
        $start = 0.0;
        $all = array_values($plan['pieces']);
        $last = \count($all) - 1;
        $duration = static fn (int $i): float => (float) ($timed['p'.$i]['durationSeconds'] ?? $all[$i]['plannedSeconds']);
        foreach ($all as $i => $piece) {
            $seconds = $duration($i);
            $position = $i + 1;
            $fadeIn = $i > 0 && 'fade' === $all[$i - 1]['transition'];
            $fadeOut = $i < $last && 'fade' === $piece['transition'];
            $render = ['duration' => $seconds, 'fadeIn' => $fadeIn, 'fadeOut' => $fadeOut, 'background' => $style->primary];
            switch ($piece['kind']) {
                case 'image_motion':
                    $source = $image($i, 'start');
                    if (null === $source) {
                        $warnings[] = \sprintf('La imagen de la pieza %d no se pudo hacer: va sobre el color de la marca.', $position);
                    }
                    $render += null === $source ? ['kind' => 'card'] : ['kind' => 'image', 'source' => $source, 'motion' => $piece['motion'] ?? 'zoom_in'];
                    break;
                case 'text_card':
                    $background = null === ($piece['card']['background'] ?? null) ? null : $image($i, 'background');
                    $render += ['kind' => 'card'] + (null === $background ? [] : ['source' => $background, 'motion' => 'zoom_in']);
                    // Over an image the text goes in a box of the colour of the brand.
                    $cards[] = ['text' => (string) $piece['card']['text'], 'start' => $start, 'end' => $start + $seconds, 'style' => null === $background ? 'Card' : 'CardBox'];
                    break;
                case 'logo_card':
                    $background = null === ($piece['logo']['background'] ?? null) ? null : $image($i, 'background');
                    if (null === $logoFile) {
                        $warnings[] = 'No hay logo: el cierre va como tarjeta de texto.';
                    } elseif (null === $background) {
                        // On a plain colour, the colour that stands out most from the logo.
                        $render['background'] = LogoColors::backgroundFor(LogoColors::average($logoFile), $style);
                    }
                    $render += ['kind' => null === $logoFile ? 'card' : 'logo', 'logo' => $logoFile] + (null === $background ? [] : ['source' => $background, 'motion' => 'zoom_in']);
                    if ('' !== trim((string) ($piece['logo']['text'] ?? ''))) {
                        $cards[] = ['text' => (string) $piece['logo']['text'], 'start' => $start, 'end' => $start + $seconds, 'style' => null === $logoFile ? 'Card' : 'Under'];
                    }
                    break;
                default:
                    $clip = $media['clips'][$i] ?? null;
                    if (!\is_string($clip) || !is_file($clip)) {
                        throw new \RuntimeException(\sprintf('Falta el clip de la pieza %d.', $position));
                    }
                    $render += ['kind' => 'clip', 'source' => $clip];
            }
            $pieces[] = $render;
            $windows[$position] = [$start, $start + $seconds];
            if (\in_array($render['kind'], ['card', 'logo'], true) && !isset($render['source'])) {
                $plain[] = [$start, $start + $seconds, $render['background']];
            }
            if ($fadeOut) {
                $fades[] = ['at' => $start + $seconds, 'out' => Ffmpeg::fadeSeconds($seconds), 'in' => Ffmpeg::fadeSeconds($duration($i + 1))];
            }
            $start += $seconds;
        }

        $overlays = [];
        $logo = null;
        foreach ($plan['overlays'] ?? [] as $overlay) {
            $from = $windows[(int) $overlay['fromPiece']][0] ?? null;
            $to = $windows[(int) $overlay['toPiece']][1] ?? null;
            if (null === $from || null === $to || $to <= $from) {
                continue;
            }
            if ('logo' === $overlay['kind']) {
                $logo ??= ['start' => $from, 'end' => $to];
                continue;
            }
            $overlays[] = ['kind' => $overlay['kind'], 'text' => $overlay['text'], 'start' => $from, 'end' => $to, 'position' => $overlay['position']];
        }
        if (null !== $logo && null !== $logoFile) {
            // The small logo is never over a logo_card, where the logo is already big, nor over
            // a piece on the colour of the brand, where it is not seen.
            $blocked = $plain;
            foreach ($all as $i => $piece) {
                if ('logo_card' === $piece['kind']) {
                    $blocked[] = $windows[$i + 1];
                }
            }
            $logoWindows = self::logoWindows($logo, $blocked);
            $logo = [] === $logoWindows ? null : ['path' => $logoFile, 'start' => $logoWindows[0]['start'], 'end' => end($logoWindows)['end'], 'windows' => $logoWindows];
        } else {
            $logo = null;
        }
        $words = false !== ($plan['subtitles'] ?? true) ? array_map(static fn (array $w) => ['text' => (string) $w['text'], 'start' => (float) $w['start'], 'end' => (float) $w['end']], $timeline['words'] ?? []) : [];

        $ass = $out.'.ass';
        file_put_contents($ass, AssBuilder::build($width, $height, $style, $overlays, $cards, [], $words, ['plain' => $plain, 'fades' => $fades, 'logo' => null !== $logo]));
        try {
            $result = $this->renderer->render([
                'width' => $width, 'height' => $height, 'pieces' => $pieces, 'ass' => $ass, 'fontsDir' => $this->fonts->dir(), 'logo' => $logo,
                'voice' => $media['voice'] ?? null, 'music' => $media['music'] ?? null, 'musicVolume' => $this->sound->musicVolume(), 'out' => $out,
            ]);
        } finally {
            @unlink($ass);
        }

        return ['durationSeconds' => round($result['duration'], 3), 'warnings' => $warnings];
    }

    /**
     * The windows of the small logo: its window without the pieces where it must not be (a
     * logo_card, where the logo is already big: the same logo twice looks like a mistake; and a
     * piece on the colour of the brand, where a logo of that colour is not seen). Pieces of less
     * than 0,05 s left are dropped.
     *
     * @param array{start: float, end: float} $logo
     * @param list<array{0: float, 1: float}> $blocked
     *
     * @return list<array{start: float, end: float}>
     */
    public static function logoWindows(array $logo, array $blocked): array
    {
        $windows = [['start' => (float) $logo['start'], 'end' => (float) $logo['end']]];
        foreach ($blocked as [$from, $to]) {
            $next = [];
            foreach ($windows as $w) {
                if ($to <= $w['start'] + 0.001 || $from >= $w['end'] - 0.001) {
                    $next[] = $w;
                    continue;
                }
                if ($from > $w['start']) {
                    $next[] = ['start' => $w['start'], 'end' => (float) $from];
                }
                if ($to < $w['end']) {
                    $next[] = ['start' => (float) $to, 'end' => $w['end']];
                }
            }
            $windows = $next;
        }
        usort($windows, static fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return array_values(array_filter($windows, static fn (array $w) => $w['end'] - $w['start'] >= 0.05));
    }
}
