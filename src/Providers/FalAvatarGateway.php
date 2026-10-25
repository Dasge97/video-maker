<?php

declare(strict_types=1);

namespace App\Providers;

use App\Sound\SoundCatalog;

/**
 * The person speaking to camera with Kling AI Avatar v2 on fal.ai: the video is made from the
 * start image of the piece and its slice of the voice, so the mouth follows the audio and the
 * video lasts as long as the audio. It replaces the lip-sync over a finished clip, which left
 * the face out of step with the voice and frozen at the end.
 *
 *   POST https://queue.fal.run/fal-ai/kling-video/ai-avatar/v2/standard
 *        {image_url, audio_url, prompt} → the same queue as the clips (FalVideoGateway)
 *
 * The image and the audio go as data URIs, as new-studio sent the audio of the lip-sync.
 */
final class FalAvatarGateway
{
    public function __construct(
        private readonly FalVideoGateway $fal,
        private readonly SoundCatalog $sound,
    ) {
    }

    /**
     * @return array{endpoint: string, submitted: Submitted}
     */
    public function submit(string $imagePath, string $audioPath, string $prompt, ?string $model = null): array
    {
        $endpoint = (string) $this->sound->avatar($model)['endpoint'];

        return ['endpoint' => $endpoint, 'submitted' => $this->fal->submitBody($endpoint, self::body($imagePath, $audioPath, $prompt))];
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(string $imagePath, string $audioPath, string $prompt): array
    {
        $audioType = str_ends_with(strtolower($audioPath), '.mp3') ? 'audio/mpeg' : 'audio/wav';

        return [
            'image_url' => FalVideoGateway::frameUri($imagePath, (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($imagePath), '9:16'),
            'audio_url' => 'data:'.$audioType.';base64,'.base64_encode((string) file_get_contents($audioPath)),
            'prompt' => $prompt,
        ];
    }
}
