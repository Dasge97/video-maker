<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\Step;
use App\Providers\ElevenLabsVoiceGateway;
use App\Render\Ffmpeg;
use App\Sound\SoundCatalog;
use App\Sound\VoiceTimeline;

/**
 * The voice goes first because it fixes how long each piece lasts. The whole voice is read in
 * one call to ElevenLabs (the person in talking_person, the narrator in voice_over), so a
 * sentence that goes on over the next piece keeps its intonation. VoiceTimeline cuts it into
 * the pieces and gives the duration of each and the words for the subtitles; voice.wav is the
 * voice already placed on the timeline of the video.
 *
 * The audio is kept by the hash of the voice, the model and the text (audio/speech-<hash>.mp3
 * with its times beside it): if the text does not change, it is not paid again.
 */
final class VoiceStep implements Step
{
    private const SPEAKER = 'voice';

    public function __construct(
        private readonly ElevenLabsVoiceGateway $voices,
        private readonly SoundCatalog $sound,
        private readonly Ffmpeg $ffmpeg,
    ) {
    }

    public function name(): string
    {
        return 'voice';
    }

    public function run(Job $job): bool
    {
        $plan = (array) $job->plan();
        $pieces = [];
        foreach (array_values($plan['pieces']) as $i => $piece) {
            $text = trim((string) ($piece['voice'] ?? ''));
            $pieces[] = ['key' => 'p'.$i, 'plannedSeconds' => (float) $piece['plannedSeconds'], 'voice' => '' === $text ? null : ['speaker' => self::SPEAKER, 'text' => $text]];
        }
        $texts = VoiceTimeline::speakerTexts($pieces);
        $speeches = [];
        $audio = null;
        if (isset($texts[self::SPEAKER])) {
            [$audio, $speeches[self::SPEAKER]] = $this->speech($job, (string) $job->input()['voiceId'], $texts[self::SPEAKER]['text']);
        }
        $timeline = VoiceTimeline::build($pieces, $speeches);
        file_put_contents($job->path('audio/timeline.json'), json_encode($timeline, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $job->data['outputs']['timeline'] = 'audio/timeline.json';
        $job->data['outputs']['voice'] = null;
        if (null !== $audio) {
            $this->voiceTrack($timeline['pieces'], $job->path($audio), $job->path('audio/voice.wav'));
            $job->data['outputs']['voice'] = 'audio/voice.wav';
        }
        $job->log(\sprintf('Voz: %s s en total', $timeline['totalSeconds']));

        return true;
    }

    /**
     * The audio of a text and its times, from disk or from ElevenLabs.
     *
     * @return array{0: string, 1: array{characters: list<string>, starts: list<float>, ends: list<float>, duration: float}}
     */
    private function speech(Job $job, string $voiceId, string $text): array
    {
        $hash = substr(hash('sha256', implode('|', ['elevenlabs', $this->voices->model(), $voiceId, $text])), 0, 24);
        $mp3 = 'audio/speech-'.$hash.'.mp3';
        $json = 'audio/speech-'.$hash.'.json';
        if (!is_file($job->path($mp3)) || !is_file($job->path($json))) {
            $speech = $this->voices->speak($voiceId, $text);
            rename($speech->path, $job->path($mp3));
            file_put_contents($job->path($json), json_encode($speech->alignment() + ['duration' => $speech->duration()], \JSON_UNESCAPED_UNICODE));
            $job->addCost(\sprintf('Voz (%d caracteres)', mb_strlen($text)), $this->sound->voiceUsd(mb_strlen($text)));
            $job->log('Voz pedida a ElevenLabs: '.mb_strlen($text).' caracteres');
        }
        $alignment = json_decode((string) file_get_contents($job->path($json)), true, 512, \JSON_THROW_ON_ERROR);

        return [$mp3, $alignment];
    }

    /**
     * voice.wav: each piece gets its slice of the voice (with the short silence before the first
     * word when the video starts speaking) or silence, as long as the piece.
     *
     * @param list<array{key: string, startSeconds: float, durationSeconds: float, voice: array<string, mixed>|null}> $pieces
     */
    private function voiceTrack(array $pieces, string $source, string $out): void
    {
        $dir = \dirname($out).'/parts';
        is_dir($dir) || mkdir($dir, 0775, true);
        $files = [];
        try {
            foreach ($pieces as $i => $piece) {
                $files[] = $part = \sprintf('%s/part-%03d.wav', $dir, $i);
                $d = Ffmpeg::seconds((float) $piece['durationSeconds']);
                $slice = $piece['voice'];
                $encode = ['-ar', '44100', '-ac', '1', '-c:a', 'pcm_s16le', '-y', $part];
                if (null === $slice) {
                    $this->ffmpeg->exec(array_merge(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-t', $d, '-i', 'anullsrc=channel_layout=mono:sample_rate=44100'], $encode));
                    continue;
                }
                $lead = (int) round(1000 * (float) ($slice['lead'] ?? 0));
                $filter = \sprintf('atrim=start=%s:end=%s,asetpts=PTS-STARTPTS,%sapad=whole_dur=%s,atrim=0:%s', Ffmpeg::seconds((float) $slice['from']), Ffmpeg::seconds((float) $slice['to']), $lead > 0 ? \sprintf('adelay=%d:all=1,', $lead) : '', $d, $d);
                $this->ffmpeg->exec(array_merge(['ffmpeg', '-v', 'error', '-i', $source, '-af', $filter], $encode));
            }
            $files[] = $list = $dir.'/list.txt';
            file_put_contents($list, implode("\n", array_map(static fn (string $f) => "file '".str_replace('\\', '/', $f)."'", \array_slice($files, 0, -1)))."\n");
            $this->ffmpeg->exec(['ffmpeg', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-y', $out]);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
