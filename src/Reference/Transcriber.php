<?php

declare(strict_types=1);

namespace App\Reference;

use App\Account\CurrentAccount;
use App\Providers\ElevenLabsClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transcription of the audio of a video reference, with the keys of the account: through its own
 * AI Hub when it has one (`POST /v1/transcribe`, result by polling `GET /v1/jobs/{id}`), and
 * otherwise with the speech to text of ElevenLabs. With TRANSCRIBE_FAKE=1 (the tests) it never
 * calls anything. When it fails, the analysis works with the frames only and says so.
 */
final class Transcriber
{
    /** Model and price of the speech to text of ElevenLabs (0,40 $ the hour). */
    public const ELEVENLABS_MODEL = 'scribe_v1';
    public const ELEVENLABS_USD_PER_HOUR = 0.40;
    /** A pause this long, or this many words, starts a new segment. */
    private const SEGMENT_PAUSE = 0.6;
    private const SEGMENT_WORDS = 14;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CurrentAccount $account,
        private readonly ElevenLabsClient $elevenLabs,
        #[Autowire('%env(default::TRANSCRIBE_FAKE)%')]
        private readonly ?string $fake = null,
        private readonly int $timeoutSeconds = 600,
    ) {
    }

    /**
     * @return array<string, mixed> {available, text, language, segments[{start,end,text}]} or {available: false, reason}
     */
    public function transcribe(string $path, float $seconds = 0.0): array
    {
        if ('1' === $this->fake) {
            return ['available' => true, 'simulated' => true, 'text' => 'Transcripción simulada.', 'language' => 'es', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Transcripción simulada.']]];
        }
        $url = rtrim($this->account->aihubUrl(), '/');
        $key = $this->account->key('aihub');

        return '' !== $url && '' !== $key ? $this->withAiHub($path, $url, $key) : $this->withElevenLabs($path, $seconds);
    }

    /**
     * @return array<string, mixed>
     */
    private function withElevenLabs(string $path, float $seconds): array
    {
        try {
            $form = new FormDataPart(['model_id' => self::ELEVENLABS_MODEL, 'timestamps_granularity' => 'word', 'tag_audio_events' => 'false', 'file' => DataPart::fromPath($path)]);
            $result = $this->elevenLabs->request('POST', '/v1/speech-to-text', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => 300,
            ])->toArray();
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'ElevenLabs no ha podido transcribir el audio ('.mb_substr($e->getMessage(), 0, 200).').'];
        }

        return [
            'available' => true,
            'text' => mb_substr((string) ($result['text'] ?? ''), 0, 20000),
            'language' => $result['language_code'] ?? null,
            'segments' => self::segments(\is_array($result['words'] ?? null) ? $result['words'] : []),
            'usd' => round($seconds / 3600 * self::ELEVENLABS_USD_PER_HOUR, 4),
        ];
    }

    /**
     * The words of ElevenLabs joined in segments: a new one after a full stop, a pause or
     * SEGMENT_WORDS words.
     *
     * @param list<array<string, mixed>> $words
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    public static function segments(array $words): array
    {
        $segments = [];
        $current = null;
        foreach ($words as $word) {
            if ('word' !== ($word['type'] ?? 'word')) {
                continue;
            }
            $text = trim((string) ($word['text'] ?? ''));
            $start = (float) ($word['start'] ?? 0);
            $end = (float) ($word['end'] ?? $start);
            if ('' === $text) {
                continue;
            }
            if (null !== $current && ($start - $current['end'] > self::SEGMENT_PAUSE || $current['words'] >= self::SEGMENT_WORDS)) {
                $segments[] = $current;
                $current = null;
            }
            $current ??= ['start' => $start, 'end' => $end, 'text' => '', 'words' => 0];
            $current['text'] = ltrim($current['text'].' '.$text);
            $current['end'] = $end;
            ++$current['words'];
            if (1 === preg_match('/[.!?…]$/u', $text)) {
                $segments[] = $current;
                $current = null;
            }
        }
        if (null !== $current) {
            $segments[] = $current;
        }

        return \array_slice(array_map(static fn (array $s) => ['start' => round($s['start'], 2), 'end' => round($s['end'], 2), 'text' => mb_substr($s['text'], 0, 500)], $segments), 0, 400);
    }

    /**
     * @return array<string, mixed>
     */
    private function withAiHub(string $path, string $url, #[\SensitiveParameter] string $key): array
    {
        try {
            $form = new FormDataPart(['file' => DataPart::fromPath($path), 'request' => (string) json_encode(['timestamps' => 'segment'])]);
            $response = $this->http->request('POST', $url.'/v1/transcribe', [
                'auth_bearer' => $key,
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => 120,
            ]);
            $job = $response->toArray();
            $jobId = (string) ($job['job_id'] ?? '');
            $deadline = time() + $this->timeoutSeconds;
            while ('' !== $jobId && time() < $deadline) {
                sleep(3);
                $state = $this->http->request('GET', $url.'/v1/jobs/'.rawurlencode($jobId), ['auth_bearer' => $key, 'timeout' => 30])->toArray(false);
                $status = $state['status'] ?? '';
                if ('succeeded' === $status) {
                    $result = $state['result'] ?? [];

                    return [
                        'available' => true,
                        'text' => mb_substr((string) ($result['text'] ?? ''), 0, 20000),
                        'language' => $result['language'] ?? null,
                        'segments' => \array_slice(array_values(array_map(static fn ($s) => ['start' => (float) ($s['start'] ?? 0), 'end' => (float) ($s['end'] ?? 0), 'text' => mb_substr((string) ($s['text'] ?? ''), 0, 500)], \is_array($result['segments'] ?? null) ? $result['segments'] : [])), 0, 400),
                    ];
                }
                if (\in_array($status, ['failed', 'cancelled'], true)) {
                    return ['available' => false, 'reason' => 'AI Hub no ha podido transcribir el audio.'];
                }
            }

            return ['available' => false, 'reason' => 'La transcripción ha tardado demasiado.'];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'AI Hub no está disponible ahora mismo.'];
        }
    }
}
