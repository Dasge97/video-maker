<?php

declare(strict_types=1);

namespace App\Render;

/**
 * The montage by layers with ffmpeg (free):
 *
 * - clip: the clip scaled to the format, trimmed or held on its last frame.
 * - image: an image with a slow zoom (zoompan) or a slow pan (a moving crop).
 * - card: the colour of the brand, or a generated background with a slow zoom; its text
 *   comes from the ASS layer.
 * - product: the image of the product over the colour of the brand; name and offer from the ASS.
 * - logo: the logo of the brand big and centred, fading in, over a generated background
 *   with a slow zoom (or the colour of the brand); its short text from the ASS.
 *
 * The segments are joined, then one pass burns the ASS (texts on screen and subtitles word by
 * word), lays the logo and mixes the audio: the voice on top and the music lowered by a
 * sidechain compressor while someone speaks (ducking).
 *
 * The ASS reads the fonts of the program (fontsDir, VideoFonts); the small logo goes
 * top left inside the safe zone of TikTok and Reels, only in its windows; the ducking is softer
 * and the mix ends at -14 LUFS (loudnorm, what the apps play); the music, composed a few seconds
 * longer than the video, fades out at its end.
 */
final class FfmpegRenderer implements Renderer
{
    /** Seconds of the fade out of the music at the end of the video. */
    public const MUSIC_FADE = 1.5;

    public function __construct(private readonly Ffmpeg $ffmpeg)
    {
    }

    public function render(array $job): array
    {
        $width = (int) $job['width'];
        $height = (int) $job['height'];
        $dir = (string) tempnam(sys_get_temp_dir(), 'render');
        @unlink($dir);
        mkdir($dir, 0700, true);
        $files = [];
        try {
            $segments = [];
            $total = 0.0;
            foreach (array_values($job['pieces']) as $i => $piece) {
                $files[] = $segments[] = $segment = \sprintf('%s/piece-%03d.mp4', $dir, $i);
                $this->piece($piece, $segment, $width, $height);
                $total += (float) $piece['duration'];
            }
            $files[] = $joined = $dir.'/joined.mp4';
            $this->ffmpeg->concat($segments, $joined);
            $this->final($job, $joined, $total, $width);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $probe = $this->ffmpeg->probe((string) $job['out']);

        return ['duration' => $probe['duration'], 'hasAudio' => $probe['hasAudio']];
    }

    /**
     * The audio graph of the final pass: the voice padded to the whole video and split in two,
     * one copy to hear and one to drive the sidechain compressor that lowers the music.
     *
     * @return string|null filter ending in [aout]; null when there is neither voice nor music
     */
    public static function audioFilter(?int $voiceInput, ?int $musicInput, float $total, float $musicVolume): ?string
    {
        $t = Ffmpeg::seconds($total);
        $format = 'aformat=sample_rates=44100:channel_layouts=stereo';
        $voice = null === $voiceInput ? null : \sprintf('[%d:a]%s,apad=whole_dur=%s,atrim=0:%s,asetpts=PTS-STARTPTS', $voiceInput, $format, $t, $t);
        $fade = min(self::MUSIC_FADE, $total / 3);
        $music = null === $musicInput ? null : \sprintf('[%d:a]%s,volume=%s,afade=t=in:d=0.5,apad=whole_dur=%s,atrim=0:%s,afade=t=out:st=%s:d=%s,asetpts=PTS-STARTPTS',
            $musicInput, $format, number_format($musicVolume, 3, '.', ''), $t, $t, Ffmpeg::seconds(max(0, $total - $fade)), Ffmpeg::seconds($fade));
        // The level of the apps (TikTok, Reels: about -14 LUFS), without clipping.
        $loud = 'loudnorm=I=-14:TP=-1.5:LRA=11,aresample=44100';

        return match (true) {
            null !== $voice && null !== $music => $voice.',asplit=2[vo][sc];'.$music.'[mu];'
                .'[mu][sc]sidechaincompress=threshold=0.02:ratio=5:attack=20:release=700:makeup=1[duck];'
                .'[vo][duck]amix=inputs=2:duration=first:normalize=0,'.$loud.'[aout]',
            null !== $voice => $voice.','.$loud.'[aout]',
            null !== $music => $music.','.$loud.'[aout]',
            default => null,
        };
    }

    /**
     * The enable expression of the small logo: on in each of its windows (it is taken off the
     * pieces where it would not be seen or would be twice, Montage::logoWindows).
     *
     * @param list<array{start: float, end: float}> $windows
     */
    public static function logoEnable(array $windows): string
    {
        return implode('+', array_map(static fn (array $w) => \sprintf('between(t,%s,%s)', Ffmpeg::seconds((float) $w['start']), Ffmpeg::seconds((float) $w['end'])), $windows));
    }

    /**
     * @param array<string, mixed> $piece
     */
    private function piece(array $piece, string $out, int $width, int $height): void
    {
        $seconds = max(0.1, (float) $piece['duration']);
        $d = Ffmpeg::seconds($seconds);
        $fade = Ffmpeg::fadeSeconds($seconds);
        $fades = [];
        if (true === ($piece['fadeIn'] ?? false)) {
            $fades[] = \sprintf('fade=t=in:st=0:d=%s', Ffmpeg::seconds($fade));
        }
        if (true === ($piece['fadeOut'] ?? false)) {
            $fades[] = \sprintf('fade=t=out:st=%s:d=%s', Ffmpeg::seconds($seconds - $fade), Ffmpeg::seconds($fade));
        }
        // Every segment in limited range and tagged alike: a JPEG gave a full-range segment
        // (yuvj420p), ffmpeg rebuilt the filters of the final pass in the middle of the video, its
        // setpts started again from 0 and the last pieces were lost (first test of video-maker).
        $encode = ['-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', ...Ffmpeg::COLOR_TAGS, '-r', (string) Ffmpeg::FPS, '-t', $d, '-y', $out];
        $background = BrandStyle::ffmpeg((string) ($piece['background'] ?? BrandStyle::DEFAULT_PRIMARY));

        $source = \is_string($piece['source'] ?? null) ? $piece['source'] : null;
        if ('card' === $piece['kind'] && null !== $source) {
            // A card over a generated background: the background moves like an image.
            $piece['kind'] = 'image';
        }
        switch ($piece['kind']) {
            case 'logo':
                $background = null === $source
                    ? ['-f', 'lavfi', '-i', \sprintf('color=c=%s:s=%dx%d:d=%s:r=%d', $background, $width, $height, $d, Ffmpeg::FPS)]
                    : ['-framerate', (string) Ffmpeg::FPS, '-loop', '1', '-t', $d, '-i', $source];
                $base = null === $source ? '[0:v]setsar=1[bg]' : '[0:v]'.$this->motion((string) ($piece['motion'] ?? 'zoom_in'), $seconds, $width, $height).',setsar=1[bg]';
                $graph = $base.';'
                    // The logo a little above the centre (25 % to 51 % of the height at most), so its
                    // text (AssBuilder «Under») and the subtitles fit below it without touching.
                    .\sprintf('[1:v]scale=%d:%d:force_original_aspect_ratio=decrease,format=rgba,fade=t=in:st=0:d=%s:alpha=1[logo];', (int) round($width * 0.62 / 2) * 2, (int) round($height * 0.26 / 2) * 2, Ffmpeg::seconds(min(0.5, $seconds / 3)))
                    .'[bg][logo]overlay=(W-w)/2:H*0.38-h/2,'.Ffmpeg::LIMITED
.([] === $fades ? '' : ','.implode(',', $fades)).'[v]';
                $this->ffmpeg->exec(array_merge(
                    ['ffmpeg', '-v', 'error'], $background, ['-loop', '1', '-t', $d, '-i', (string) $piece['logo'], '-filter_complex', $graph, '-map', '[v]'],
                    $encode,
                ));
                break;
            case 'clip':

                $this->ffmpeg->segment((string) $piece['source'], $out, $seconds, $width, $height, true === ($piece['fadeIn'] ?? false), true === ($piece['fadeOut'] ?? false));
                break;
            case 'image':
                $filters = array_merge([$this->motion((string) ($piece['motion'] ?? 'zoom_in'), $seconds, $width, $height), 'setsar=1', Ffmpeg::LIMITED], $fades);
                $this->ffmpeg->exec(array_merge(['ffmpeg', '-v', 'error', '-framerate', (string) Ffmpeg::FPS, '-loop', '1', '-t', $d, '-i', (string) $piece['source'], '-vf', implode(',', $filters)], $encode));
                break;
            case 'product':
                // Between its name and offer (AssBuilder «Name» and «Offer», from the top of the
                // safe zone) and the subtitles: from 33 % to 67 % of the height at most.
                $graph = \sprintf('[1:v]scale=%d:%d:force_original_aspect_ratio=decrease,format=rgba[p];[0:v][p]overlay=(W-w)/2:H*0.5-h/2,setsar=1,'.Ffmpeg::LIMITED, (int) round($width * 0.72 / 2) * 2, (int) round($height * 0.34 / 2) * 2)
                    .([] === $fades ? '' : ','.implode(',', $fades)).'[v]';
                $this->ffmpeg->exec(array_merge(
                    ['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', \sprintf('color=c=%s:s=%dx%d:d=%s:r=%d', $background, $width, $height, $d, Ffmpeg::FPS), '-loop', '1', '-t', $d, '-i', (string) $piece['source'], '-filter_complex', $graph, '-map', '[v]'],
                    $encode,
                ));
                break;
            default:
                $this->ffmpeg->exec(array_merge(
                    ['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', \sprintf('color=c=%s:s=%dx%d:d=%s:r=%d', $background, $width, $height, $d, Ffmpeg::FPS)],
                    ['-vf', implode(',', array_merge([Ffmpeg::LIMITED], $fades))],
                    $encode,
                ));
        }
    }

    /** A slow zoom or pan of an image that covers the whole frame. */
    private function motion(string $motion, float $seconds, int $width, int $height): string
    {
        $frames = max(1, (int) round($seconds * Ffmpeg::FPS));
        $cover = \sprintf('scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d', 2 * $width, 2 * $height, 2 * $width, 2 * $height);
        if (\in_array($motion, ['zoom_in', 'zoom_out'], true)) {
            $zoom = 'zoom_in' === $motion ? \sprintf('1+0.15*on/%d', $frames) : \sprintf('1.15-0.15*on/%d', $frames);

            return $cover.\sprintf(",zoompan=z='%s':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s=%dx%d:fps=%d", $zoom, $width, $height, Ffmpeg::FPS);
        }
        $big = \sprintf('scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d', (int) round($width * 1.2 / 2) * 2, (int) round($height * 1.2 / 2) * 2, (int) round($width * 1.2 / 2) * 2, (int) round($height * 1.2 / 2) * 2);
        $p = \sprintf('min(1,t/%s)', Ffmpeg::seconds($seconds));
        [$x, $y] = match ($motion) {
            'pan_left' => ["(iw-ow)*(1-$p)", '(ih-oh)/2'],
            'pan_up' => ['(iw-ow)/2', "(ih-oh)*(1-$p)"],
            'pan_down' => ['(iw-ow)/2', "(ih-oh)*$p"],
            default => ["(iw-ow)*$p", '(ih-oh)/2'],
        };

        return $big.\sprintf(",crop=%d:%d:x='%s':y='%s'", $width, $height, $x, $y);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function final(array $job, string $video, float $total, int $width): void
    {
        $inputs = ['-i', $video];
        $next = 1;
        $logo = $job['logo'] ?? null;
        $logoInput = null;
        if (\is_array($logo) && is_file((string) $logo['path'])) {
            array_push($inputs, '-loop', '1', '-t', Ffmpeg::seconds($total), '-i', (string) $logo['path']);
            $logoInput = $next++;
        }
        $voiceInput = null;
        if (null !== ($job['voice'] ?? null) && is_file((string) $job['voice'])) {
            array_push($inputs, '-i', (string) $job['voice']);
            $voiceInput = $next++;
        }
        $musicInput = null;
        if (null !== ($job['music'] ?? null) && is_file((string) $job['music'])) {
            array_push($inputs, '-i', (string) $job['music']);
            $musicInput = $next++;
        }

        $video = '[0:v]setpts=PTS-STARTPTS';
        if (null !== ($job['ass'] ?? null) && is_file((string) $job['ass'])) {
            $fonts = \is_string($job['fontsDir'] ?? null) && is_dir($job['fontsDir']) ? ':fontsdir='.self::filterPath($job['fontsDir']) : '';
            $video .= ',ass=filename='.self::filterPath((string) $job['ass']).$fonts;
        }
        $graph = [];
        if (null !== $logoInput) {
            $height = (int) $job['height'];
            $windows = \is_array($logo['windows'] ?? null) ? $logo['windows'] : [['start' => (float) $logo['start'], 'end' => (float) $logo['end']]];
            $graph[] = $video.'[base]';
            // In the safe zone of TikTok and Reels: never top right (their buttons), from 12 % of
            // the height, inside a box of AssBuilder::LOGO_WIDTH × LOGO_HEIGHT.
            $graph[] = \sprintf('[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,format=rgba[logo]', $logoInput, max(16, (int) round($width * AssBuilder::LOGO_WIDTH / 2) * 2), max(16, (int) round($height * AssBuilder::LOGO_HEIGHT / 2) * 2));
            $graph[] = \sprintf("[base][logo]overlay=%d:%d:enable='%s',format=yuv420p[vout]", (int) round($width * AssBuilder::LOGO_LEFT), (int) round($height * AssBuilder::SAFE_TOP), self::logoEnable($windows));
        } else {
            $graph[] = $video.',format=yuv420p[vout]';
        }
        // A video always has an audio track, silent when there is neither voice nor music.
        $audio = self::audioFilter($voiceInput, $musicInput, $total, (float) ($job['musicVolume'] ?? 0.35));
        if (null === $audio) {
            array_push($inputs, '-f', 'lavfi', '-t', Ffmpeg::seconds($total), '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100');
            $audio = \sprintf('[%d:a]anull[aout]', $next++);
        }
        $graph[] = $audio;

        $this->ffmpeg->exec(array_merge(['ffmpeg', '-v', 'error'], $inputs, [
            '-filter_complex', implode(';', $graph),
            '-map', '[vout]', '-map', '[aout]',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', (string) Ffmpeg::FPS,
            '-c:a', 'aac', '-b:a', '160k', '-ar', '44100',
            '-t', Ffmpeg::seconds($total), '-movflags', '+faststart', '-y', (string) $job['out'],
        ]), 1800);
    }

    /** A path inside a filter graph: «:», «'» and «\» escaped. */
    private static function filterPath(string $path): string
    {
        return "'".str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $path)."'";
    }
}
