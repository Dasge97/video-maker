<?php

declare(strict_types=1);

namespace App\Reference;

/**
 * The frames of the reference that the agent sees: one in the middle of each shot, so it sees
 * every shot once and knows how long each lasts. With fewer than MIN_CUTS cuts (a single take,
 * or cuts the detector misses) the video is sampled at fixed intervals instead.
 */
final class Frames
{
    public const MIN_CUTS = 4;
    /** Two cuts closer than this are one (a flash or a fast transition). */
    private const MIN_SHOT = 0.3;

    public function __construct(private readonly VideoTools $tools)
    {
    }

    /**
     * Extracts the frames into $dir (frame-00.jpg…) and returns what frames.json keeps.
     *
     * @return list<array{index: int, atSeconds: float, shotStart: float, shotEnd: float, path: string}> path relative to $dir
     */
    public function extract(string $video, float $duration, string $dir): array
    {
        $frames = [];
        foreach (self::plan($duration, $this->tools->cuts($video)) as $shot) {
            $name = \sprintf('frame-%02d.jpg', \count($frames));
            if ($this->tools->frame($video, $shot['atSeconds'], $dir.'/'.$name)) {
                $frames[] = ['index' => \count($frames)] + $shot + ['path' => $name];
            }
        }
        if ([] === $frames) {
            throw new InputRejected('No se han podido sacar fotogramas del vídeo de referencia.');
        }

        return $frames;
    }

    /**
     * Where to take each frame.
     *
     * @param list<float> $cuts
     *
     * @return list<array{atSeconds: float, shotStart: float, shotEnd: float}>
     */
    public static function plan(float $duration, array $cuts): array
    {
        $bounds = [0.0];
        foreach ($cuts as $cut) {
            if ($cut - end($bounds) >= self::MIN_SHOT && $duration - $cut >= self::MIN_SHOT) {
                $bounds[] = $cut;
            }
        }
        $bounds[] = $duration;

        if (\count($bounds) - 2 < self::MIN_CUTS) {
            // Few cuts: fixed intervals, one every 2 s at most VideoTools::MAX_FRAMES.
            $count = max(1, min(VideoTools::MAX_FRAMES, (int) ceil($duration / 2)));
            $bounds = [];
            for ($i = 0; $i <= $count; ++$i) {
                $bounds[] = $duration * $i / $count;
            }
        }

        $shots = [];
        for ($i = 0; $i < \count($bounds) - 1; ++$i) {
            $shots[] = ['atSeconds' => round(($bounds[$i] + $bounds[$i + 1]) / 2, 2), 'shotStart' => round($bounds[$i], 2), 'shotEnd' => round($bounds[$i + 1], 2)];
        }
        if (\count($shots) <= VideoTools::MAX_FRAMES) {
            return $shots;
        }
        // Too many shots: an even selection, always with the first and the last.
        $picked = [];
        $last = \count($shots) - 1;
        for ($k = 0; $k < VideoTools::MAX_FRAMES; ++$k) {
            $picked[] = $shots[(int) round($k * $last / (VideoTools::MAX_FRAMES - 1))];
        }

        return $picked;
    }
}
