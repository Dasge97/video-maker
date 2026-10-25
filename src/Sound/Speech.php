<?php

declare(strict_types=1);

namespace App\Sound;

/**
 * A text read aloud: the audio file (temporary, the caller stores or deletes it) and when each
 * character starts and ends, as ElevenLabs returns them («with timestamps»).
 */
final class Speech
{
    /**
     * @param list<string> $characters
     * @param list<float>  $starts
     * @param list<float>  $ends
     */
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly array $characters,
        public readonly array $starts,
        public readonly array $ends,
    ) {
    }

    public function duration(): float
    {
        return [] === $this->ends ? 0.0 : (float) max($this->ends);
    }

    /**
     * @return array{characters: list<string>, starts: list<float>, ends: list<float>}
     */
    public function alignment(): array
    {
        return ['characters' => $this->characters, 'starts' => $this->starts, 'ends' => $this->ends];
    }
}
