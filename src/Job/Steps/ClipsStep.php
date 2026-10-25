<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\JobStore;
use App\Job\Step;
use App\Job\StepFailed;
use App\Prompt\PromptBuilder;
use App\Providers\FalAvatarGateway;
use App\Providers\FalVideoGateway;
use App\Providers\GatewayJob;
use App\Providers\JobState;
use App\Providers\ProviderUncertain;
use App\Providers\VideoCatalog;
use App\Providers\VideoRejected;
use App\Providers\VideoTask;
use App\Render\Ffmpeg;
use App\Sound\SoundCatalog;

/**
 * The clips that cost money, on fal.ai:
 * - talking_person: Kling AI Avatar v2 with the start image of the piece (or the photo) and the
 *   slice of voice.wav that sounds on it; the video lasts as long as the audio.
 * - generated_clip: Kling O3 reference-to-video with the person as element when she appears,
 *   the start image as first frame and the frame of the reference it imitates; it is asked the
 *   whole second above the piece, and the montage cuts the rest.
 *
 * Never paid twice. Each clip is known by the hash of what is asked (clips/<hash>.mp4); its
 * request_id is written to job.json as soon as fal answers, before anything else, and a clip
 * sent is asked for its state, never sent again. A failed download is retried alone. A clip
 * without an answer after ABANDON_SECONDS is cancelled in fal. The cost is written only when fal
 * gives back the finished clip.
 */
final class ClipsStep implements Step
{
    /** Clips in fal's queue at once. */
    private const MAX_IN_FLIGHT = 3;
    private const POLL_SECONDS = 10;
    private const ABANDON_SECONDS = 1200;
    /** Kling O3 takes four images: elements and references together. */
    private const MAX_IMAGES = 4;

    public function __construct(
        private readonly FalVideoGateway $fal,
        private readonly FalAvatarGateway $avatar,
        private readonly VideoCatalog $videos,
        private readonly SoundCatalog $sound,
        private readonly PromptBuilder $prompts,
        private readonly Ffmpeg $ffmpeg,
        private readonly JobStore $store,
    ) {
    }

    public function name(): string
    {
        return 'clips';
    }

    public function run(Job $job): bool
    {
        if (time() - (int) ($job->data['clipsPolledAt'] ?? 0) < self::POLL_SECONDS && $this->inFlight($job) > 0) {
            return false;
        }
        $job->data['clipsPolledAt'] = time();
        $wanted = $this->wanted($job);
        $clips = [];
        $done = 0;
        foreach ($wanted as $i => $clip) {
            $file = 'clips/'.$clip['hash'].'.mp4';
            if (!is_file($job->path($file))) {
                $this->advance($job, $i, $clip, $file);
            }
            if (is_file($job->path($file))) {
                $clips[$i] = $file;
                ++$done;
            }
        }
        $job->data['outputs']['clips'] = $clips;
        $job->data['outputs']['clipsProgress'] = ['done' => $done, 'total' => \count($wanted)];

        return $done === \count($wanted);
    }

    /**
     * One clip one step further: sent, asked for, or downloaded.
     *
     * @param array<string, mixed> $clip
     */
    private function advance(Job $job, int $i, array $clip, string $file): void
    {
        $key = 'clip:'.$clip['hash'];
        $entry = $job->data['providerJobs'][$key] ?? null;
        $n = $i + 1;
        if (null === $entry) {
            if ($this->inFlight($job) >= self::MAX_IN_FLIGHT) {
                return;
            }
            try {
                $sent = $clip['submit']();
            } catch (ProviderUncertain $e) {
                $job->data['providerJobs'][$key] = ['state' => 'uncertain', 'piece' => $n, 'submittedAt' => date(\DATE_ATOM)];
                $job->log(\sprintf('Clip %d: envío incierto: %s', $n, $e->getMessage()));
                throw new StepFailed(\sprintf('No sabemos si fal.ai recibió el clip %d. Reintentar puede cobrarlo dos veces.', $n));
            } catch (VideoRejected $e) {
                throw new StepFailed(\sprintf('fal.ai ha rechazado el clip %d: %s', $n, $e->getMessage()));
            }
            $job->data['providerJobs'][$key] = [
                'endpoint' => $sent['endpoint'], 'requestId' => $sent['submitted']->requestId,
                'statusUrl' => $sent['submitted']->meta['statusUrl'] ?? null, 'responseUrl' => $sent['submitted']->meta['responseUrl'] ?? null,
                'cancelUrl' => $sent['submitted']->meta['cancelUrl'] ?? null,
                'submittedAt' => date(\DATE_ATOM), 'state' => 'submitted', 'piece' => $n, 'usd' => $clip['usd'], 'what' => $clip['what'],
            ];
            // Written before anything else: if the worker stops now, the clip is not sent again.
            $this->store->save($job);
            $job->log(\sprintf('Clip %d enviado a fal.ai (%s): %s', $n, $sent['endpoint'], $sent['submitted']->requestId));

            return;
        }
        if ('uncertain' === $entry['state']) {
            throw new StepFailed(\sprintf('No sabemos si fal.ai recibió el clip %d. Reintentar puede cobrarlo dos veces.', $n));
        }
        $gatewayJob = GatewayJob::fromArray($entry);
        if ('completed' !== $entry['state']) {
            $state = $this->fal->status($gatewayJob);
            if (JobState::FAILED === $state->state) {
                unset($job->data['providerJobs'][$key]);
                throw new StepFailed(\sprintf('fal.ai no ha podido hacer el clip %d: %s', $n, mb_substr((string) $state->error, 0, 300)));
            }
            if (JobState::COMPLETED !== $state->state) {
                if (time() - strtotime((string) $entry['submittedAt']) > self::ABANDON_SECONDS) {
                    $this->fal->cancel($gatewayJob);
                    unset($job->data['providerJobs'][$key]);
                    throw new StepFailed(\sprintf('El clip %d llevaba 20 minutos sin terminar y se ha cancelado en fal.ai. Reintenta para pedirlo otra vez.', $n));
                }

                return;
            }
            // Finished: charged now, whatever happens with the download.
            $job->data['providerJobs'][$key]['state'] = 'completed';
            $job->addCost($entry['what'], (float) $entry['usd']);
            $this->store->save($job);
        }
        // A failed download is «unavailable»: the worker tries again, the clip is not asked again.
        $output = $this->fal->output($gatewayJob);
        $video = $output->first('video') ?? $output->files[0];
        rename($video['path'], $job->path($file));
        $job->data['providerJobs'][$key]['state'] = 'downloaded';
        $job->log(\sprintf('Clip %d descargado', $n));
    }

    private function inFlight(Job $job): int
    {
        return \count(array_filter($job->data['providerJobs'] ?? [], static fn (array $e) => \in_array($e['state'] ?? null, ['submitted', 'completed'], true)));
    }

    /**
     * The clips of the plan as they would be asked now: by piece, their hash and how to send them.
     *
     * @return array<int, array{hash: string, usd: float, what: string, submit: callable(): array{endpoint: string, submitted: \App\Providers\Submitted}}>
     */
    private function wanted(Job $job): array
    {
        $plan = (array) $job->plan();
        $timeline = json_decode((string) file_get_contents($job->path((string) $job->data['outputs']['timeline'])), true, 512, \JSON_THROW_ON_ERROR);
        $timed = [];
        foreach ($timeline['pieces'] as $t) {
            $timed[$t['key']] = $t;
        }
        $images = $job->data['outputs']['images'] ?? [];
        $frames = json_decode((string) file_get_contents($job->path('reference/frames.json')), true, 512, \JSON_THROW_ON_ERROR);
        $model = $this->videos->get($job->input()['model'] ?? null);
        $fonts = [(string) ($job->input()['brand']['font'] ?? '')];
        $person = $job->path('input/person.jpg');
        $wanted = [];
        foreach (array_values($plan['pieces']) as $i => $piece) {
            $scene = $piece['scene'] ?? null;
            $seconds = (float) ($timed['p'.$i]['durationSeconds'] ?? $piece['plannedSeconds']);
            $start = isset($images[$i]['start']) ? $job->path($images[$i]['start']) : null;
            if ('talking_person' === $piece['kind']) {
                $image = $start ?? $person;
                $audio = $this->slice($job, $timed['p'.$i] ?? null, $seconds);
                $prompt = PromptBuilder::withoutWriting((string) $scene['action'], $fonts);
                $avatar = $this->sound->avatar();
                $hash = substr(hash('sha256', implode('|', ['avatar', $avatar['endpoint'], $prompt, hash_file('sha256', $image), hash_file('sha256', $audio)])), 0, 24);
                $wanted[$i] = [
                    'hash' => $hash,
                    'usd' => $this->sound->avatarUsd($seconds),
                    'what' => \sprintf('Pieza %d: persona hablando (%s s)', $i + 1, number_format($seconds, 1, ',', '')),
                    'submit' => fn () => $this->avatar->submit($image, $audio, $prompt),
                ];
            } elseif ('generated_clip' === $piece['kind']) {
                $billed = VideoCatalog::billedSeconds($model, $seconds);
                $references = [];
                if (null !== $start) {
                    $references[] = ['role' => 'first_frame', 'type' => 'start_frame', 'path' => $start, 'mime' => 'image/jpeg', 'name' => 'the start image', 'citation' => null];
                }
                if (true === ($scene['withPerson'] ?? false)) {
                    // fal takes the element together with the start image (first probe,
                    // 2026-10-25): her photo keeps the face when the clip moves away from it.
                    $references[] = ['role' => 'element', 'path' => $person, 'mime' => 'image/jpeg', 'name' => 'the person', 'citation' => '@Element1'];
                }
                $frame = $scene['imitateFrame'] ?? null;
                if (null !== $frame && isset($frames[$frame]) && \count($references) < self::MAX_IMAGES) {
                    $references[] = ['role' => 'reference', 'path' => $job->path('reference/'.$frames[$frame]['path']), 'mime' => 'image/jpeg', 'name' => 'reference', 'citation' => '@Image1', 'imitate' => ['movement', 'framing']];
                }
                $prompt = $this->prompts->clipPrompt(['spec' => ['action' => $scene['action'], 'framing' => $scene['framing'], 'cameraMovement' => $scene['camera'], 'lighting' => $scene['lighting']]], $references, '', $fonts);
                $hash = substr(hash('sha256', implode('|', array_merge(['clip', $model['video']['endpoint'], $prompt, (string) $billed], array_map(static fn (array $r) => $r['role'].':'.hash_file('sha256', $r['path']), $references)))), 0, 24);
                $task = new VideoTask($model['video']['endpoint'], $prompt, array_map(static fn (array $r) => ['role' => 'reference' === $r['role'] ? 'image' : $r['role'], 'path' => $r['path'], 'mime' => $r['mime']], $references), $billed, '9:16', $model);
                $wanted[$i] = [
                    'hash' => $hash,
                    'usd' => VideoCatalog::clipUsd($model, $billed),
                    'what' => \sprintf('Pieza %d: clip (%s, %d s)', $i + 1, $model['model'], $billed),
                    'submit' => fn () => ['endpoint' => $task->endpoint, 'submitted' => $this->fal->submit($task)],
                ];
            }
        }

        return $wanted;
    }

    /**
     * The slice of voice.wav that sounds on a piece, at least as long as the avatar model takes
     * (padded with silence). Free: cut from the voice already paid.
     *
     * @param array<string, mixed>|null $timed
     */
    private function slice(Job $job, ?array $timed, float $seconds): string
    {
        $from = (float) ($timed['startSeconds'] ?? 0);
        $min = (float) $this->sound->avatar()['minSeconds'];
        $voice = $job->path('audio/voice.wav');
        $out = $job->path(\sprintf('audio/slice-%s.wav', substr(hash('sha256', implode('|', [hash_file('sha256', $voice), $from, $seconds, $min])), 0, 24)));
        if (!is_file($out)) {
            $this->ffmpeg->exec(['ffmpeg', '-v', 'error', '-i', $voice, '-af', \sprintf('atrim=start=%s:end=%s,asetpts=PTS-STARTPTS,apad=whole_dur=%s', Ffmpeg::seconds($from), Ffmpeg::seconds($from + $seconds), Ffmpeg::seconds(max($min, $seconds))), '-ar', '44100', '-ac', '1', '-c:a', 'pcm_s16le', '-y', $out]);
        }

        return $out;
    }
}
