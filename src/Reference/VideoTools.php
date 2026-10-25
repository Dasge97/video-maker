<?php

declare(strict_types=1);

namespace App\Reference;

use Symfony\Component\Process\Process;

/**
 * ffprobe and ffmpeg on local files. Nothing of the file name or contents reaches a shell:
 * every argument goes to the process as a separate value.
 */
final class VideoTools
{
    public const FORMATS = ['mp4' => 'mov,mp4,m4a,3gp,3g2,mj2', 'mov' => 'mov,mp4,m4a,3gp,3g2,mj2', 'webm' => 'matroska,webm'];
    public const MAX_FRAMES = 16;
    public const FRAME_WIDTH = 768;
    /** How different two frames must be to count as a cut (ffmpeg «scene» score, 0 to 1). */
    public const SCENE_THRESHOLD = 0.3;

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxSeconds,
    ) {
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxSeconds(): int
    {
        return $this->maxSeconds;
    }

    /**
     * Checks size, container and duration before anything is stored.
     *
     * @return array{extension: string, duration: float, hasAudio: bool}
     */
    public function probe(string $path): array
    {
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new InputRejected(\sprintf('El vídeo de referencia debe ocupar como mucho %d MB.', intdiv($this->maxBytes, 1024 * 1024)));
        }
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
        $process->setTimeout(60);
        $process->run();
        $info = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !\is_array($info)) {
            throw new InputRejected('El vídeo de referencia no es un vídeo válido.');
        }
        $format = (string) ($info['format']['format_name'] ?? '');
        $extension = array_search($format, self::FORMATS, true);
        $streams = \is_array($info['streams'] ?? null) ? $info['streams'] : [];
        $video = array_filter($streams, static fn ($s) => 'video' === ($s['codec_type'] ?? null) && 'mjpeg' !== ($s['codec_name'] ?? null));
        if (false === $extension || [] === $video) {
            throw new InputRejected('Formato de vídeo no admitido. Usa mp4, mov o webm.');
        }
        $duration = (float) ($info['format']['duration'] ?? 0);
        if ($duration <= 0 || $duration > $this->maxSeconds) {
            throw new InputRejected(\sprintf('El vídeo de referencia debe durar como mucho %d segundos (dura %d).', $this->maxSeconds, (int) round($duration)));
        }

        return [
            'extension' => (string) $extension,
            'duration' => $duration,
            'hasAudio' => [] !== array_filter($streams, static fn ($s) => 'audio' === ($s['codec_type'] ?? null)),
        ];
    }

    /**
     * The real cuts of the video: the moments where a frame differs from the previous one by
     * more than SCENE_THRESHOLD, read from the «pts_time» that showinfo prints.
     *
     * @return list<float> seconds, in order
     */
    public function cuts(string $path): array
    {
        $process = new Process(['ffmpeg', '-hide_banner', '-i', $path, '-an', '-vf', \sprintf("select='gt(scene,%s)',showinfo", self::SCENE_THRESHOLD), '-f', 'null', '-']);
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        return self::parseCuts($process->getErrorOutput());
    }

    /**
     * @return list<float>
     */
    public static function parseCuts(string $showinfo): array
    {
        preg_match_all('/Parsed_showinfo.*?pts_time:\s*([0-9.]+)/', $showinfo, $matches);

        return array_values(array_map(static fn (string $s) => round((float) $s, 3), $matches[1]));
    }

    /** One JPEG frame at $at seconds, FRAME_WIDTH wide. */
    public function frame(string $path, float $at, string $out): bool
    {
        $process = new Process(['ffmpeg', '-v', 'error', '-ss', (string) max(0, round($at, 2)), '-i', $path, '-frames:v', '1', '-vf', 'scale='.self::FRAME_WIDTH.':-2', '-q:v', '4', '-y', $out]);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() && is_file($out) && filesize($out) > 0;
    }

    /** Only the audio, mono at 16 kHz, so the transcription does not upload the whole video. */
    public function audio(string $path, string $out): bool
    {
        $process = new Process(['ffmpeg', '-v', 'error', '-i', $path, '-vn', '-ac', '1', '-ar', '16000', '-y', $out]);
        $process->setTimeout(300);
        $process->run();

        return $process->isSuccessful() && is_file($out) && filesize($out) > 0;
    }
}
