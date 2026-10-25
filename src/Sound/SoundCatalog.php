<?php

declare(strict_types=1);

namespace App\Sound;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The voice, music and talking-person models with their prices, read from config/sound.yaml
 * like the catalog of video models. Prices in dollars.
 */
final class SoundCatalog
{
    /**
     * Seconds of music composed beyond the video. ElevenLabs composes an ending for the length
     * it is asked and the music died down about 3 s before the end; now its ending is cut off
     * and the montage fades it out (FfmpegRenderer::MUSIC_FADE).
     */
    public const MUSIC_TAIL_SECONDS = 4.0;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/sound.yaml')]
        private readonly string $path,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function voice(): array
    {
        return $this->config()['voice'];
    }

    /**
     * @return array<string, mixed>
     */
    public function music(): array
    {
        return $this->config()['music'];
    }

    /**
     * The model of the talking person, or the default one.
     *
     * @return array{key: string, endpoint: string, usdPerSecond: float, minSeconds: float, maxSeconds: float}
     */
    public function avatar(?string $key = null): array
    {
        $avatar = $this->config()['avatar'];
        $key ??= (string) $avatar['default'];
        $model = $avatar['models'][$key] ?? $avatar['models'][(string) $avatar['default']];

        return ['key' => $key] + $model;
    }

    /** Dollars of a text read aloud. */
    public function voiceUsd(int $characters): float
    {
        return $characters / 1000 * (float) $this->voice()['usdPer1000Chars'];
    }

    public function musicUsd(float $seconds): float
    {
        return max((float) $this->music()['minSeconds'], $seconds) / 60 * (float) $this->music()['usdPerMinute'];
    }

    /** Dollars of a talking-person video of $seconds (fal bills the seconds of video). */
    public function avatarUsd(float $seconds, ?string $key = null): float
    {
        $model = $this->avatar($key);

        return max((float) $model['minSeconds'], $seconds) * (float) $model['usdPerSecond'];
    }

    public function musicVolume(): float
    {
        return (float) ($this->music()['volume'] ?? 0.35);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        if (null === $this->config) {
            $raw = Yaml::parseFile($this->path);
            $this->config = \is_array($raw) ? $raw : [];
        }

        return $this->config;
    }
}
