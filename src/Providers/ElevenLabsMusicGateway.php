<?php

declare(strict_types=1);

namespace App\Providers;

use App\Sound\SoundCatalog;

/**
 * ElevenLabs music (https://elevenlabs.io/docs/api-reference/music/compose):
 *
 *   POST /v1/music?output_format=mp3_44100_128
 *        {prompt, music_length_ms (3000 to 600000), model_id: music_v1, force_instrumental: true}
 *   → the audio file
 *
 * Always instrumental: the voice of the video goes on top.
 */
final class ElevenLabsMusicGateway
{
    public function __construct(
        private readonly ElevenLabsClient $client,
        private readonly SoundCatalog $catalog,
    ) {
    }

    /**
     * @return array{path: string, extension: string} a temporary file the caller moves
     */
    public function compose(string $prompt, float $seconds): array
    {
        $config = $this->catalog->music();
        $ms = (int) max(1000 * (float) $config['minSeconds'], min(600000, (int) ceil($seconds * 1000)));
        $response = $this->client->request('POST', '/v1/music', [
            'query' => ['output_format' => (string) $config['outputFormat']],
            'json' => ['prompt' => $prompt, 'music_length_ms' => $ms, 'model_id' => (string) $config['modelId'], 'force_instrumental' => true],
            'timeout' => 300,
        ]);
        $bytes = $response->getContent();
        if ('' === $bytes) {
            throw new VideoRejected('ElevenLabs no ha devuelto la música.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'music').'.mp3';
        file_put_contents($path, $bytes);

        return ['path' => $path, 'extension' => 'mp3'];
    }
}
