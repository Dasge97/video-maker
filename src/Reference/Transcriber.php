<?php

declare(strict_types=1);

namespace App\Reference;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transcription of the audio of a video reference through AI Hub (`POST /v1/transcribe`, result
 * by polling `GET /v1/jobs/{id}`). Without configuration, or with AIHUB_URL=fake in tests, it
 * never calls anything: the analysis then works with the frames only and says so.
 */
final class Transcriber
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 600,
    ) {
    }

    public function isConfigured(): bool
    {
        return 'fake' === $this->url || ('' !== $this->url && '' !== $this->apiKey);
    }

    /**
     * @return array<string, mixed> {available, text, language, segments[{start,end,text}]} or {available: false, reason}
     */
    public function transcribe(string $path): array
    {
        if ('fake' === $this->url) {
            return ['available' => true, 'simulated' => true, 'text' => 'Transcripción simulada.', 'language' => 'es', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Transcripción simulada.']]];
        }
        if (!$this->isConfigured()) {
            return ['available' => false, 'reason' => 'AI Hub no está configurado: el desglose usa solo los fotogramas.'];
        }
        try {
            $form = new FormDataPart(['file' => DataPart::fromPath($path), 'request' => (string) json_encode(['timestamps' => 'segment'])]);
            $response = $this->http->request('POST', rtrim($this->url, '/').'/v1/transcribe', [
                'auth_bearer' => $this->apiKey,
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => 120,
            ]);
            $job = $response->toArray();
            $jobId = (string) ($job['job_id'] ?? '');
            $deadline = time() + $this->timeoutSeconds;
            while ('' !== $jobId && time() < $deadline) {
                sleep(3);
                $state = $this->http->request('GET', rtrim($this->url, '/').'/v1/jobs/'.rawurlencode($jobId), ['auth_bearer' => $this->apiKey, 'timeout' => 30])->toArray(false);
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
