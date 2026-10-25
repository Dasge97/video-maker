<?php

declare(strict_types=1);

namespace App\Render;

use Symfony\Component\Process\Process;

/**
 * ffmpeg and ffprobe for clips, thumbnails and the final montage. Every argument
 * goes to the process as a separate value: nothing reaches a shell.
 */
final class Ffmpeg
{
    public const FPS = 24;
    /** Every segment ends in limited range (a JPEG or a clip may come in full range). */
    public const LIMITED = 'scale=out_range=tv:out_color_matrix=bt709,format=yuv420p';
    /** The same colour tags on every segment, so the joined video never changes them midway. */
    public const COLOR_TAGS = ['-color_range', 'tv', '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709'];

    /**
     * @return array{duration: float, width: int, height: int, hasAudio: bool}
     */
    public function probe(string $path): array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
        $process->setTimeout(60);
        $process->run();
        $info = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !\is_array($info)) {
            throw new \RuntimeException('El archivo no es un vídeo válido.');
        }
        $streams = \is_array($info['streams'] ?? null) ? $info['streams'] : [];
        $video = array_values(array_filter($streams, static fn ($s) => 'video' === ($s['codec_type'] ?? null)));
        $duration = (float) ($info['format']['duration'] ?? 0);
        if ([] === $video || $duration <= 0) {
            throw new \RuntimeException('El archivo no contiene vídeo.');
        }

        return [
            'duration' => $duration,
            'width' => (int) ($video[0]['width'] ?? 0),
            'height' => (int) ($video[0]['height'] ?? 0),
            'hasAudio' => [] !== array_filter($streams, static fn ($s) => 'audio' === ($s['codec_type'] ?? null)),
        ];
    }

    /** Seconds of any media file, audio included; 0 when it cannot be read. */
    public function duration(string $path): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() ? (float) trim($process->getOutput()) : 0.0;
    }

    /** JPEG of one frame, at most 480 px wide. */
    public function thumbnail(string $video, float $at, string $out): void
    {
        $this->run(['ffmpeg', '-v', 'error', '-ss', (string) max(0, round($at, 2)), '-i', $video, '-frames:v', '1', '-vf', 'scale=480:-2', '-q:v', '4', '-y', $out]);
        if (!is_file($out) || 0 === filesize($out)) {
            // Very short clips: the first frame always exists.
            $this->run(['ffmpeg', '-v', 'error', '-i', $video, '-frames:v', '1', '-vf', 'scale=480:-2', '-q:v', '4', '-y', $out]);
        }
    }

    /** Seconds of the fade of a piece that lasts $seconds (to black, and back). */
    public static function fadeSeconds(float $seconds): float
    {
        return min(0.4, max(0.0, $seconds) / 4);
    }

    /**
     * One segment of the montage: the clip scaled to cover the format and cut to it (it used
     * to be padded with black, and MiniMax gave 480×832 for a video of 480×854), at a fixed frame
     * rate, trimmed or held on its last frame to the duration of the plan, with optional fades.
     */
    public function segment(string $clip, string $out, float $seconds, int $width, int $height, bool $fadeIn, bool $fadeOut): void
    {
        $fade = self::fadeSeconds($seconds);
        $filters = [
            \sprintf('scale=%d:%d:force_original_aspect_ratio=increase', $width, $height),
            \sprintf('crop=%d:%d', $width, $height),
            'setsar=1',
            self::LIMITED,
            'fps='.self::FPS,
            \sprintf('tpad=stop_mode=clone:stop_duration=%s', self::seconds($seconds)),
            \sprintf('trim=duration=%s', self::seconds($seconds)),
            'setpts=PTS-STARTPTS',
        ];
        if ($fadeIn) {
            $filters[] = \sprintf('fade=t=in:st=0:d=%s', self::seconds($fade));
        }
        if ($fadeOut) {
            $filters[] = \sprintf('fade=t=out:st=%s:d=%s', self::seconds($seconds - $fade), self::seconds($fade));
        }
        $this->run(['ffmpeg', '-v', 'error', '-i', $clip, '-vf', implode(',', $filters), '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', ...self::COLOR_TAGS, '-r', (string) self::FPS, '-y', $out], 900);
    }

    /**
     * @param list<string> $segments same codec, size and frame rate
     */
    public function concat(array $segments, string $out): void
    {
        $list = (string) tempnam(sys_get_temp_dir(), 'concat');
        file_put_contents($list, implode("\n", array_map(static fn (string $s) => "file '".str_replace("'", "'\\''", $s)."'", $segments))."\n");
        try {
            $this->run(['ffmpeg', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-an', '-movflags', '+faststart', '-y', $out], 900);
        } finally {
            @unlink($list);
        }
    }

    /**
     * @param list<string> $command
     */
    public function exec(array $command, int $timeout = 900): void
    {
        $this->run($command, $timeout);
    }

    public static function seconds(float $seconds): string
    {
        return number_format(max(0, $seconds), 3, '.', '');
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, int $timeout = 300): void
    {
        if ('ffmpeg' === $command[0]) {
            // Never waits for a key: launched from a console it hung reading its input.
            array_splice($command, 1, 0, ['-nostdin']);
        }
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg: '.mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
    }

}
