<?php

declare(strict_types=1);

namespace App\Providers;

use App\Account\CurrentAccount;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Calls to the API of ElevenLabs (https://elevenlabs.io/docs/api-reference) with the key of
 * the account in the header xi-api-key. A refused request (4xx) is a rejection with the message
 * of ElevenLabs; a busy or unreachable service is «unavailable» and nothing is charged.
 */
final class ElevenLabsClient
{
    public const BASE = 'https://api.elevenlabs.io';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CurrentAccount $account,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->account->key('elevenlabs'));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $key = $this->account->key('elevenlabs');
        MissingKey::check($key, 'ElevenLabs');
        try {
            $response = $this->http->request($method, self::BASE.$path, $options + [
                'headers' => ['xi-api-key' => $key],
                'timeout' => 120,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new ProviderUnavailable('No se ha podido conectar con ElevenLabs: '.$e->getMessage(), 0, $e);
        }
        if (429 === $status || $status >= 500) {
            throw new ProviderUnavailable('ElevenLabs está ocupado ('.$status.'). Reintenta en un rato.');
        }
        if ($status >= 400) {
            throw new VideoRejected(self::message($response, $status));
        }

        return $response;
    }

    private static function message(ResponseInterface $response, int $status): string
    {
        try {
            $body = $response->toArray(false);
        } catch (\Throwable) {
            $body = [];
        }
        $detail = $body['detail'] ?? null;
        $text = match (true) {
            \is_string($detail) => $detail,
            \is_array($detail) && \is_string($detail['message'] ?? null) => $detail['message'],
            \is_array($detail) && \is_array($detail[0] ?? null) => (string) ($detail[0]['msg'] ?? ''),
            default => '',
        };

        return mb_substr(('' === $text ? 'ElevenLabs rechazó la petición' : 'ElevenLabs: '.$text).' ('.$status.')', 0, 300);
    }
}
