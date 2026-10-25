<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Result of a finished job, already downloaded to local temporary files that the caller moves
 * to the folder of the video and then deletes.
 */
final class JobOutput
{
    /**
     * @param list<array{path: string, contentType: string}> $files
     * @param array<string, mixed>                           $raw   provider answer without the URLs
     */
    public function __construct(
        public readonly array $files,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @return array{path: string, contentType: string}|null
     */
    public function first(string $prefix): ?array
    {
        foreach ($this->files as $file) {
            if (str_starts_with($file['contentType'], $prefix)) {
                return $file;
            }
        }

        return null;
    }

    public function cleanUp(): void
    {
        foreach ($this->files as $file) {
            @unlink($file['path']);
        }
    }
}
