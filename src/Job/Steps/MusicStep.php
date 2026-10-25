<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\Step;
use App\Job\StepFailed;
use App\Providers\ElevenLabsMusicGateway;
use App\Providers\ProviderUnavailable;
use App\Providers\VideoRejected;
use App\Sound\SoundCatalog;

/**
 * The music, with ElevenLabs, SoundCatalog::MUSIC_TAIL_SECONDS longer than the video: its own
 * ending is cut and the montage fades it out. The music never stops the video: if it fails, the
 * video goes without it and says so. Kept by the hash of the prompt and the length.
 */
final class MusicStep implements Step
{
    public function __construct(
        private readonly ElevenLabsMusicGateway $music,
        private readonly SoundCatalog $sound,
    ) {
    }

    public function name(): string
    {
        return 'music';
    }

    public function run(Job $job): bool
    {
        $job->data['outputs']['music'] = null;
        $music = $job->plan()['music'] ?? null;
        if (!\is_array($music)) {
            return true;
        }
        $timeline = json_decode((string) file_get_contents($job->path((string) $job->data['outputs']['timeline'])), true, 512, \JSON_THROW_ON_ERROR);
        $prompt = self::prompt((string) $music['style'], (string) $music['mood'], (string) $this->sound->music()['defaultStyle']);
        $seconds = (float) $timeline['totalSeconds'] + SoundCatalog::MUSIC_TAIL_SECONDS;
        $file = 'audio/music-'.substr(hash('sha256', implode('|', ['elevenlabs', $prompt, (string) ceil($seconds)])), 0, 24).'.mp3';
        if (!is_file($job->path($file))) {
            try {
                $made = $this->music->compose($prompt, $seconds);
            } catch (StepFailed|VideoRejected|ProviderUnavailable $e) {
                $job->warn('La música no se pudo generar: el vídeo va sin música. ('.$e->getMessage().')');
                $job->log('Música fallida: '.$e->getMessage());

                return true;
            }
            rename($made['path'], $job->path($file));
            $job->addCost('Música', $this->sound->musicUsd($seconds));
        }
        $job->data['outputs']['music'] = $file;

        return true;
    }

    /** No seconds in the prompt («sube a los 8 s»): the music is asked longer and faded out. */
    public static function prompt(string $style, string $mood, string $defaultStyle): string
    {
        $style = self::withoutSeconds($style);
        $mood = self::withoutSeconds($mood);

        return trim(('' !== $style ? $style : $defaultStyle).('' !== $mood ? '. '.$mood : '')).'. Música instrumental, sin voces, para un vídeo corto de redes sociales, con energía constante hasta el final.';
    }

    public static function withoutSeconds(string $text): string
    {
        $parts = preg_split('/(?<=[,.;])\s*/u', trim($text)) ?: [];
        $kept = array_filter($parts, static fn (string $p) => '' !== trim($p, ' ,.;') && 1 !== preg_match('/\d+([.,]\d+)?\s*(s|seg|segs|segundos?|seconds?|secs?|minutos?|minutes?|min)\b|\b\d+[.,]?\d*\s*["”]/iu', $p));

        return trim(rtrim(implode(' ', $kept), ' ,;'));
    }
}
