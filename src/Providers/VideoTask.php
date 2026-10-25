<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * One clip for fal: the endpoint, the prompt, the images and how long.
 */
final class VideoTask
{
    /**
     * @param list<array{role: string, path: string, mime: string}> $references role: element, image or first_frame
     * @param array<string, mixed>                                   $model      catalog entry (VideoCatalog)
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $prompt,
        public readonly array $references,
        public readonly int $durationSeconds,
        public readonly string $aspectRatio,
        public readonly array $model,
    ) {
    }
}
