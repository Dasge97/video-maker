<?php

declare(strict_types=1);

namespace App\Providers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The catalog of video models from config/models.yaml, normalised.
 */
final class VideoCatalog
{
    /** @var list<array<string, mixed>>|null */
    private ?array $models = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/models.yaml')]
        private readonly string $catalogPath,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (null === $this->models) {
            $raw = Yaml::parseFile($this->catalogPath);
            $this->models = array_values(array_map(self::normalise(...), \is_array($raw['models'] ?? null) ? $raw['models'] : []));
        }

        return $this->models;
    }

    /**
     * The model asked, or the default one.
     *
     * @return array<string, mixed>
     */
    public function get(?string $id): array
    {
        $default = null;
        foreach ($this->all() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
            if ($model['default']) {
                $default = $model;
            }
        }

        return $default ?? $this->all()[0];
    }

    /**
     * Seconds sent to the model for a piece: rounded up to whole seconds and to the range the
     * model accepts (a piece of 2,4 s is asked as 3 s, and the montage cuts it).
     *
     * @param array<string, mixed> $model
     */
    public static function billedSeconds(array $model, float $planSeconds): int
    {
        $wanted = max(1, (int) ceil($planSeconds - 0.001));
        $durations = $model['video']['durations'];

        return max($durations['min'], min($durations['max'], $wanted));
    }

    /**
     * Dollars of one clip.
     *
     * @param array<string, mixed> $model
     */
    public static function clipUsd(array $model, int $billedSeconds): float
    {
        return $billedSeconds * (float) $model['video']['usdPerSecond'];
    }

    /**
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    private static function normalise(array $m): array
    {
        $video = \is_array($m['video'] ?? null) ? $m['video'] : [];
        $durations = \is_array($video['durations'] ?? null) ? $video['durations'] : [];

        return [
            'id' => (string) ($m['id'] ?? ''),
            'label' => (string) ($m['label'] ?? ''),
            'model' => (string) ($m['model'] ?? ''),
            'resolution' => (string) ($m['resolution'] ?? '720p'),
            'default' => true === ($m['default'] ?? false),
            'video' => [
                'endpoint' => (string) ($video['endpoint'] ?? ''),
                'durations' => ['min' => (int) ($durations['min'] ?? 1), 'max' => (int) ($durations['max'] ?? 10)],
                'durationAsString' => true === ($video['durationAsString'] ?? false),
                'maxReferences' => max(0, (int) ($video['maxReferences'] ?? 0)),
                'startImageParam' => \is_string($video['startImageParam'] ?? null) && '' !== $video['startImageParam'] ? $video['startImageParam'] : 'start_image_url',
                'usdPerSecond' => (float) ($video['usdPerSecond'] ?? 0),
                'secondsPerClip' => max(1, (int) ($video['secondsPerClip'] ?? 60)),
            ],
        ];
    }
}
