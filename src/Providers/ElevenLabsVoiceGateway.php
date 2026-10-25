<?php

declare(strict_types=1);

namespace App\Providers;

use App\Sound\SoundCatalog;
use App\Sound\Speech;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ElevenLabs text to speech with timestamps:
 *
 *   POST /v1/text-to-speech/{voice_id}/with-timestamps?output_format=mp3_44100_128
 *        {text, model_id: eleven_multilingual_v2}
 *   → {audio_base64, alignment: {characters, character_start_times_seconds,
 *      character_end_times_seconds}, normalized_alignment}
 *
 * The alignment (not the normalized one) follows the characters of the text sent, so the cuts
 * of the pieces can be found in it. The voices come from GET /v2/voices (cached one hour in
 * var/cache); a sample is the preview_url of the voice.
 */
final class ElevenLabsVoiceGateway
{
    private const VOICES_CACHE = 'elevenlabs_voices';

    public function __construct(
        private readonly ElevenLabsClient $client,
        private readonly HttpClientInterface $http,
        private readonly SoundCatalog $catalog,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function model(): string
    {
        return (string) $this->catalog->voice()['modelId'];
    }

    public function speak(string $voiceId, string $text): Speech
    {
        $config = $this->catalog->voice();
        $body = ['text' => $text, 'model_id' => $this->model()];
        if (true === ($config['sendLanguageCode'] ?? false)) {
            $body['language_code'] = (string) $config['languageCode'];
        }
        $response = $this->client->request('POST', '/v1/text-to-speech/'.rawurlencode($voiceId).'/with-timestamps', [
            'query' => ['output_format' => (string) $config['outputFormat']],
            'json' => $body,
        ]);
        $data = $response->toArray(false);
        $audio = base64_decode((string) ($data['audio_base64'] ?? ''), true);
        $alignment = \is_array($data['alignment'] ?? null) ? $data['alignment'] : (\is_array($data['normalized_alignment'] ?? null) ? $data['normalized_alignment'] : null);
        if (false === $audio || '' === $audio || null === $alignment) {
            throw new VideoRejected('ElevenLabs no ha devuelto audio con marcas de tiempo.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'voice').'.mp3';
        file_put_contents($path, $audio);

        return new Speech(
            $path,
            'mp3',
            array_map('strval', \is_array($alignment['characters'] ?? null) ? $alignment['characters'] : []),
            array_map('floatval', \is_array($alignment['character_start_times_seconds'] ?? null) ? $alignment['character_start_times_seconds'] : []),
            array_map('floatval', \is_array($alignment['character_end_times_seconds'] ?? null) ? $alignment['character_end_times_seconds'] : []),
        );
    }

    /**
     * @return list<array{id: string, name: string, description: string, labels: array<string, string>, previewUrl: string|null}>
     */
    public function voices(): array
    {
        $item = $this->cache->getItem(self::VOICES_CACHE);
        if ($item->isHit() && \is_array($item->get())) {
            return $item->get();
        }
        $voices = [];
        $token = null;
        for ($page = 0; $page < 5; ++$page) {
            $data = $this->client->request('GET', '/v2/voices', ['query' => array_filter([
                'page_size' => 100, 'include_total_count' => 'false', 'next_page_token' => $token,
            ])])->toArray(false);
            foreach (\is_array($data['voices'] ?? null) ? $data['voices'] : [] as $voice) {
                if (!\is_string($voice['voice_id'] ?? null)) {
                    continue;
                }
                $voices[] = [
                    'id' => $voice['voice_id'],
                    'name' => (string) ($voice['name'] ?? $voice['voice_id']),
                    'description' => mb_substr((string) ($voice['description'] ?? ''), 0, 300),
                    'labels' => array_map('strval', array_filter(\is_array($voice['labels'] ?? null) ? $voice['labels'] : [], 'is_scalar')),
                    'previewUrl' => \is_string($voice['preview_url'] ?? null) ? $voice['preview_url'] : null,
                ];
            }
            $token = \is_string($data['next_page_token'] ?? null) ? $data['next_page_token'] : null;
            if (true !== ($data['has_more'] ?? false) || null === $token) {
                break;
            }
        }
        $item->set($voices)->expiresAfter(3600);
        $this->cache->save($item);

        return $voices;
    }

    /**
     * @return array{path: string, contentType: string} a temporary file the caller deletes
     */
    public function sample(string $voiceId): array
    {
        foreach ($this->voices() as $voice) {
            if ($voice['id'] === $voiceId && null !== $voice['previewUrl'] && str_starts_with($voice['previewUrl'], 'https://')) {
                try {
                    $response = $this->http->request('GET', $voice['previewUrl'], ['timeout' => 30]);
                    if (200 === $response->getStatusCode()) {
                        $path = (string) tempnam(sys_get_temp_dir(), 'sample').'.mp3';
                        file_put_contents($path, $response->getContent());

                        return ['path' => $path, 'contentType' => 'audio/mpeg'];
                    }
                } catch (\Throwable) {
                    // Falls through to a short sentence read with the voice.
                }
            }
        }
        // Without a preview (a voice of one's own): a short sentence read with it.
        $speech = $this->speak($voiceId, 'Hola, esta es mi voz para tus vídeos.');

        return ['path' => $speech->path, 'contentType' => 'audio/mpeg'];
    }
}
