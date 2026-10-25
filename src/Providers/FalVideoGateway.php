<?php

declare(strict_types=1);

namespace App\Providers;

use Intervention\Image\Alignment;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * fal.ai through its queue (https://fal.ai/docs/model-apis/model-endpoints/queue):
 *
 *   POST https://queue.fal.run/{endpoint}  → request_id, status_url, response_url, cancel_url
 *   GET  status_url   → IN_QUEUE | IN_PROGRESS | COMPLETED (with error on failure)
 *   GET  response_url → the model output (video.url)
 *   PUT  cancel_url
 *
 * The URLs returned at submit are used as they come: for endpoints with a subpath the request
 * URLs only carry owner/app. Every request asks fal not to keep inputs and outputs in its
 * request history (X-Fal-Store-IO: 0) and to expire the generated files after a day; results
 * are downloaded to the folder of the video as soon as they are ready. Images go inline as
 * base64 data URIs.
 *
 * Errors of a submit, so nothing is paid twice:
 * - ProviderUnavailable (429, 502, 503, no connection): nothing was sent; safe to retry.
 * - VideoRejected (other 4xx): fal does not charge; the step fails with fal's message.
 * - ProviderUncertain (cut connection, timeout): it may have been charged; never retried alone.
 */
final class FalVideoGateway
{
    public const QUEUE = 'https://queue.fal.run';
    private const MAX_DOWNLOAD_BYTES = 1073741824;
    private const MEDIA_EXPIRATION_SECONDS = 86400;
    /** Widest (and, inverted, tallest) image fal takes as a reference. */
    private const MAX_RATIO = 2.5;
    /** Shortest side Kling takes (fal: «Min width: 300px, Min height: 300px»). */
    public const MIN_SIDE = 300;
    /** Long side of the references sent: enough for the models, far lighter than the originals. */
    public const MAX_SIDE = 1024;
    /** Long side of a first frame (Kling Pro makes 1080p). */
    public const MAX_FRAME_SIDE = 1536;
    /** Up to this difference of shape a first frame is cut; beyond it, it goes whole. */
    public const CROP_TOLERANCE = 1.35;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(FAL_KEY)%')]
        #[\SensitiveParameter]
        private readonly string $falKey,
    ) {
    }

    public function submit(VideoTask $task): Submitted
    {
        return $this->submitBody($task->endpoint, self::body($task));
    }

    /**
     * Sends any body to an endpoint of the queue (the talking person uses it too).
     *
     * @param array<string, mixed> $body
     */
    public function submitBody(string $endpoint, array $body): Submitted
    {
        MissingKey::check($this->falKey, 'FAL_KEY', 'fal.ai');
        $sent = false;
        try {
            $response = $this->http->request('POST', self::QUEUE.'/'.ltrim($endpoint, '/'), ['headers' => $this->headers(), 'json' => $body, 'timeout' => 60]);
            $status = $response->getStatusCode();
            $sent = true;
            $answer = $response->toArray(false);
        } catch (TimeoutExceptionInterface $e) {
            throw new ProviderUncertain('fal.ai no ha contestado a tiempo al enviar el clip.', 0, $e);
        } catch (TransportExceptionInterface $e) {
            $notConnected = !$sent && 1 === preg_match('/resolve host|connection refused|failed to connect|couldn\'t connect/i', $e->getMessage());
            throw $notConnected ? new ProviderUnavailable('No se ha podido conectar con fal.ai.', 0, $e) : new ProviderUncertain('Se cortó la conexión con fal.ai al enviar el clip.', 0, $e);
        } catch (\Throwable $e) {
            throw new ProviderUncertain('fal.ai ha contestado algo que no se entiende al enviar el clip.', 0, $e);
        }
        if (429 === $status || 502 === $status || 503 === $status) {
            throw new ProviderUnavailable('fal.ai está ocupado ('.$status.').');
        }
        if ($status >= 400 && $status < 500) {
            throw new VideoRejected('fal.ai: '.self::message($answer, $status));
        }
        $requestId = $answer['request_id'] ?? null;
        if ($status >= 300 || !\is_string($requestId) || '' === $requestId) {
            throw new ProviderUncertain('fal.ai ha contestado sin número de trabajo ('.$status.').');
        }

        return new Submitted($requestId, array_filter([
            'statusUrl' => \is_string($answer['status_url'] ?? null) ? $answer['status_url'] : null,
            'responseUrl' => \is_string($answer['response_url'] ?? null) ? $answer['response_url'] : null,
            'cancelUrl' => \is_string($answer['cancel_url'] ?? null) ? $answer['cancel_url'] : null,
        ], static fn ($v) => null !== $v));
    }

    public function status(GatewayJob $job): JobState
    {
        MissingKey::check($this->falKey, 'FAL_KEY', 'fal.ai');
        try {
            $response = $this->http->request('GET', $this->url($job, 'statusUrl', '/status'), ['headers' => $this->headers(), 'timeout' => 20]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new ProviderUnavailable('No se ha podido preguntar a fal.ai por el clip.', 0, $e);
        }
        if (404 === $status) {
            return new JobState(JobState::FAILED, 'NOT_FOUND', 'fal.ai no reconoce el trabajo.');
        }
        if ($status >= 400) {
            throw new ProviderUnavailable('fal.ai no ha dado el estado del clip ('.$status.').');
        }

        return match ($body['status'] ?? null) {
            'IN_QUEUE' => new JobState(JobState::QUEUED, 'IN_QUEUE'),
            'IN_PROGRESS' => new JobState(JobState::RUNNING, 'IN_PROGRESS'),
            'COMPLETED' => isset($body['error']) && null !== $body['error'] && '' !== $body['error']
                ? new JobState(JobState::FAILED, 'COMPLETED', self::text($body['error']))
                : new JobState(JobState::COMPLETED, 'COMPLETED'),
            default => throw new ProviderUnavailable('fal.ai ha dado un estado desconocido.'),
        };
    }

    /**
     * The result, downloaded. A failed download is «unavailable»: only the download is retried,
     * never the clip.
     */
    public function output(GatewayJob $job): JobOutput
    {
        try {
            $response = $this->http->request('GET', $this->url($job, 'responseUrl', ''), ['headers' => $this->headers(), 'timeout' => 60]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new ProviderUnavailable('No se ha podido leer el resultado de fal.ai.', 0, $e);
        }
        if ($status >= 400) {
            throw new VideoRejected('fal.ai: '.self::message($payload, $status));
        }
        $url = $payload['video']['url'] ?? null;
        if (!\is_string($url)) {
            throw new VideoRejected('La respuesta de fal.ai no trae ningún vídeo.');
        }
        try {
            $file = ['path' => $this->download($url), 'contentType' => (string) ($payload['video']['content_type'] ?? 'video/mp4')];
        } catch (\Throwable $e) {
            throw new ProviderUnavailable('No se ha podido descargar el clip de fal.ai: '.$e->getMessage(), 0, $e);
        }

        return new JobOutput([$file], self::withoutUrls($payload));
    }

    public function cancel(GatewayJob $job): void
    {
        try {
            $this->http->request('PUT', $this->url($job, 'cancelUrl', '/cancel'), ['headers' => $this->headers(), 'timeout' => 20])->getStatusCode();
        } catch (\Throwable) {
            // Best effort: the job may still finish and be charged.
        }
    }

    /**
     * Kling O3 reference-to-video: elements (@Element1) and image_urls (@Image1); duration as an
     * enum string; no native audio. The first frame (start image) goes in the field of the model
     * (start_image_url), in the shape of the clip, so the model opens on it without changing its
     * canvas.
     *
     * @return array<string, mixed>
     */
    public static function body(VideoTask $task): array
    {
        $video = \is_array($task->model['video'] ?? null) ? $task->model['video'] : [];
        $data = static fn (array $r) => self::dataUri($r['path'], $r['mime']);
        $body = [
            'prompt' => $task->prompt,
            'duration' => true === ($video['durationAsString'] ?? false) ? (string) $task->durationSeconds : $task->durationSeconds,
            'aspect_ratio' => $task->aspectRatio,
            'generate_audio' => false,
        ];
        $startField = \is_string($video['startImageParam'] ?? null) ? $video['startImageParam'] : 'start_image_url';
        foreach ($task->references as $r) {
            match ($r['role']) {
                'element' => $body['elements'][] = ['frontal_image_url' => $data($r), 'reference_image_urls' => [$data($r)]],
                'first_frame' => $body[$startField] = self::frameUri($r['path'], $r['mime'], $task->aspectRatio),
                default => $body['image_urls'][] = $data($r),
            };
        }

        return $body;
    }

    /**
     * The first frame as a data URI in the shape of the clip (9:16). Never cut blindly from the
     * centre: a shape close to the clip is cut keeping the top (the heads) or, when it is wider,
     * the centre; a shape far from it (a logo, a panorama) goes whole over a blurred copy of
     * itself. Transparency is flattened on white. Bytes that are not an image go as they are.
     */
    public static function frameUri(string $path, string $mime, string $aspectRatio): string
    {
        $bytes = (string) file_get_contents($path);
        $size = @getimagesizefromstring($bytes);
        $ratio = 1 === preg_match('/^(\d+):(\d+)$/', $aspectRatio, $m) && (int) $m[2] > 0 ? (int) $m[1] / (int) $m[2] : null;
        if (false === $size || $size[0] <= 0 || $size[1] <= 0 || null === $ratio) {
            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }
        [$width, $height] = $size;
        $image = self::flat(ImageManager::usingDriver(Driver::class)->decodeBinary($bytes));
        $actual = $width / $height;
        $far = max($actual, $ratio) / min($actual, $ratio);
        if ($far >= 1.01) {
            // The canvas of the clip, at most MAX_FRAME_SIDE: inside the image when it is cut,
            // around it when it goes whole.
            [$canvasW, $canvasH] = $ratio >= 1 ? [self::MAX_FRAME_SIDE, self::MAX_FRAME_SIDE / $ratio] : [self::MAX_FRAME_SIDE * $ratio, self::MAX_FRAME_SIDE];
            $fit = [$width / $canvasW, $height / $canvasH];
            $scale = min(1.0, $far <= self::CROP_TOLERANCE ? min($fit) : max($fit));
            [$canvasW, $canvasH] = [max(2, (int) round($canvasW * $scale)), max(2, (int) round($canvasH * $scale))];
            if ($far <= self::CROP_TOLERANCE) {
                $image->cover($canvasW, $canvasH, $actual < $ratio ? Alignment::TOP : Alignment::CENTER);
            } else {
                $background = (clone $image)->cover(max(2, intdiv($canvasW, 16)), max(2, intdiv($canvasH, 16)))->blur(4)->resize($canvasW, $canvasH);
                $background->insert($image->scaleDown($canvasW, $canvasH), 0, 0, Alignment::CENTER);
                $image = $background;
            }
        }
        $image->scaleDown(self::MAX_FRAME_SIDE, self::MAX_FRAME_SIDE);

        return 'data:image/jpeg;base64,'.base64_encode((string) $image->encode(new JpegEncoder(90)));
    }

    /**
     * An image of reference as a data URI, ready for fal: transparency flattened on white (JPEG
     * has none, and a logo with alpha came out on black), padded with white up to the widest
     * shape fal takes (2.5 times, without cutting anything: a logo of 2560×600 was refused), its
     * long side reduced to $maxSide and its short side raised to $minSide (Kling refuses less
     * than 300 px). Bytes that are not an image go as they are.
     */
    public static function dataUri(string $path, string $mime, int $minSide = self::MIN_SIDE, int $maxSide = self::MAX_SIDE): string
    {
        $bytes = (string) file_get_contents($path);
        $size = @getimagesizefromstring($bytes);
        if (false === $size || $size[0] <= 0 || $size[1] <= 0) {
            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }
        [$width, $height] = $size;
        $image = self::flat(ImageManager::usingDriver(Driver::class)->decodeBinary($bytes));
        $ratio = $width / $height;
        if ($ratio > self::MAX_RATIO) {
            $image->resizeCanvas($width, (int) ceil($width / self::MAX_RATIO), 'ffffff');
        } elseif ($ratio < 1 / self::MAX_RATIO) {
            $image->resizeCanvas((int) ceil($height / self::MAX_RATIO), $height, 'ffffff');
        }
        $image->scaleDown($maxSide, $maxSide);
        $short = min($image->width(), $image->height());
        if ($short < $minSide) {
            $image->scale((int) ceil($image->width() * $minSide / $short), (int) ceil($image->height() * $minSide / $short));
        }

        return 'data:image/jpeg;base64,'.base64_encode((string) $image->encode(new JpegEncoder(90)));
    }

    /** The image over an opaque white background. */
    private static function flat(ImageInterface $image): ImageInterface
    {
        return $image->fillTransparentAreas('ffffff');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Key '.$this->falKey,
            'X-Fal-Store-IO' => '0',
            'X-Fal-Object-Lifecycle-Preference' => (string) json_encode(['expiration_duration_seconds' => self::MEDIA_EXPIRATION_SECONDS]),
        ];
    }

    private function url(GatewayJob $job, string $key, string $suffix): string
    {
        if (\is_string($job->meta[$key] ?? null) && str_starts_with($job->meta[$key], 'https://')) {
            return $job->meta[$key];
        }
        // Built by hand: only owner/app, then requests/{id} (the queue answers 405 to subpaths).
        $parts = explode('/', $job->endpoint);

        return \sprintf('%s/%s/requests/%s%s', self::QUEUE, implode('/', \array_slice($parts, 0, 2)), rawurlencode($job->requestId), $suffix);
    }

    private function download(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('URL de resultado no admitida.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'fal');
        $out = fopen($path, 'w');
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 120, 'max_duration' => 900]);
            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException('Descarga '.$response->getStatusCode());
            }
            $bytes = 0;
            foreach ($this->http->stream($response) as $chunk) {
                $bytes += \strlen($chunk->getContent());
                if ($bytes > self::MAX_DOWNLOAD_BYTES) {
                    throw new \RuntimeException('Archivo demasiado grande.');
                }
                fwrite($out, $chunk->getContent());
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($path);
            throw $e;
        }
        fclose($out);

        return $path;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function withoutUrls(array $payload): array
    {
        array_walk_recursive($payload, static function (&$value, $key): void {
            if ('url' === $key && \is_string($value)) {
                $value = '[descargado]';
            }
        });

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function message(array $body, int $status): string
    {
        $detail = $body['detail'] ?? $body['error'] ?? $body['message'] ?? null;
        if (\is_array($detail)) {
            $detail = implode(' · ', array_map(static fn ($d) => \is_array($d) ? self::text($d['msg'] ?? json_encode($d)) : self::text($d), \array_slice($detail, 0, 3)));
        }
        $text = self::text($detail ?? '');

        return mb_substr(('' === $text ? 'rechazó la petición' : $text).($status > 0 ? " ($status)" : ''), 0, 300);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : (string) json_encode($value, \JSON_UNESCAPED_UNICODE);
    }
}
